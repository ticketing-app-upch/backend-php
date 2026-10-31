<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class PurchaseLimitTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public static function purchaseRoutes(): array
    {
        return [
            'orders then orders' => ['orders', 'orders'],
            'legacy then legacy' => ['legacy', 'legacy'],
            'orders then legacy' => ['orders', 'legacy'],
            'legacy then orders' => ['legacy', 'orders'],
        ];
    }

    public static function routes(): array
    {
        return ['orders' => ['orders'], 'legacy' => ['legacy']];
    }

    private function purchase(string $route, Event $event, User $buyer, array $items, string $key): TestResponse
    {
        $headers = $this->headersFor($buyer);
        if ($route === 'orders') {
            return $this->postJson('/api/orders', [
                'eventId' => (string) $event->id,
                'items' => $items,
                'expectedTotal' => array_sum(array_column($items, 'quantity')) * 50,
                'paymentMethod' => 'CARD', 'paymentResult' => 'APPROVED',
                'idempotencyKey' => $key,
            ], $headers);
        }

        return $this->postJson('/api/events/'.$event->id.'/tickets', [
            'items' => $items, 'payment' => $this->payment(),
        ], $headers);
    }

    #[DataProvider('purchaseRoutes')]
    public function test_bug008_blocks_a_second_purchase_after_six_tickets_across_routes_and_zones(string $firstRoute, string $secondRoute): void
    {
        $event = $this->publishedEvent();
        [$first, $second] = $event->zones;
        $buyer = $this->actor();
        $this->purchase($firstRoute, $event, $buyer, [
            ['zoneId' => $first->id, 'quantity' => 4], ['zoneId' => $second->id, 'quantity' => 2],
        ], 'first-attempt')->assertCreated()->assertJsonCount(6, 'tickets');

        foreach ([1, 6] as $quantity) {
            $this->purchase($secondRoute, $event, $buyer, [
                ['zoneId' => $second->id, 'quantity' => $quantity],
            ], 'another-attempt-'.$quantity)->assertConflict()->assertExactJson([
                'message' => 'La compra supera el máximo de 6 entradas por usuario para este evento.',
            ]);
        }

        $this->assertDatabaseCount('ticket_orders', 1);
        $this->assertDatabaseCount('tickets', 6);
        $this->assertSame(96, $first->fresh()->available_capacity);
        $this->assertSame(198, $second->fresh()->available_capacity);
    }

    #[DataProvider('purchaseRoutes')]
    public function test_partial_purchases_can_reach_six_but_cannot_exceed_it(string $firstRoute, string $secondRoute): void
    {
        $event = $this->publishedEvent();
        [$first, $second] = $event->zones;
        $buyer = $this->actor();
        $this->purchase($firstRoute, $event, $buyer, [
            ['zoneId' => $first->id, 'quantity' => 2],
        ], 'first')->assertCreated();
        $this->purchase($secondRoute, $event, $buyer, [
            ['zoneId' => $second->id, 'quantity' => 5],
        ], 'too-many')->assertConflict();
        $this->assertDatabaseCount('tickets', 2);
        $this->assertSame(200, $second->fresh()->available_capacity);
        $this->purchase($secondRoute, $event, $buyer, [
            ['zoneId' => $second->id, 'quantity' => 4],
        ], 'remaining')->assertCreated();
        $this->purchase($firstRoute, $event, $buyer, [
            ['zoneId' => $first->id, 'quantity' => 1],
        ], 'seventh')->assertConflict();
        $this->assertDatabaseCount('ticket_orders', 2);
        $this->assertDatabaseCount('tickets', 6);
    }

    #[DataProvider('routes')]
    public function test_the_cumulative_limit_is_separate_for_each_buyer_and_event(string $route): void
    {
        $event = $this->publishedEvent();
        $otherEvent = $this->publishedEvent();
        $buyer = $this->actor();
        $otherBuyer = $this->actor();
        foreach ([[$event, $buyer], [$event, $otherBuyer], [$otherEvent, $buyer]] as [$target, $client]) {
            $this->purchase($route, $target, $client, [
                ['zoneId' => $target->zones->first()->id, 'quantity' => 6],
            ], 'purchase-'.$target->id)->assertCreated();
        }
        $this->assertDatabaseCount('ticket_orders', 3);
        $this->assertDatabaseCount('tickets', 18);
    }

    #[DataProvider('purchaseRoutes')]
    public function test_cancelled_orders_release_the_buyers_allowance(string $firstRoute, string $secondRoute): void
    {
        $event = $this->publishedEvent();
        $zone = $event->zones->first();
        $buyer = $this->actor();
        $items = [['zoneId' => $zone->id, 'quantity' => 6]];
        $this->purchase($firstRoute, $event, $buyer, $items, 'cancelled-attempt')->assertCreated();
        $order = TicketOrder::sole();
        $this->patchJson('/api/orders/'.$order->id, ['status' => 'CANCELADA'], $this->headersFor($this->actor('ADMIN')))
            ->assertOk()->assertJsonPath('status', 'CANCELADA');
        $this->purchase($secondRoute, $event, $buyer, $items, 'replacement')->assertCreated();
        $this->purchase($firstRoute, $event, $buyer, [
            ['zoneId' => $zone->id, 'quantity' => 1],
        ], 'seventh')->assertConflict();
        $this->assertDatabaseCount('tickets', 12);
        $this->assertSame('CANCELADA', $order->fresh()->status);
        $this->assertSame(94, $zone->fresh()->available_capacity);
    }

    #[DataProvider('routes')]
    public function test_historical_tickets_without_an_order_count_towards_the_limit(string $route): void
    {
        $event = $this->publishedEvent();
        $zone = $event->zones->first();
        $buyer = $this->actor();
        $ticket = Ticket::create([
            'user_id' => $buyer->id, 'event_zone_id' => $zone->id,
            'quantity' => 6, 'total_price' => 300, 'unit_price' => 50, 'code' => 'historical-six',
        ]);
        $zone->decrement('available_capacity', 6);
        $this->purchase($route, $event, $buyer, [
            ['zoneId' => $zone->id, 'quantity' => 1],
        ], 'another-attempt')->assertConflict();
        $this->assertDatabaseCount('ticket_orders', 0);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertSame(6, $ticket->fresh()->quantity);
        $this->assertSame(94, $zone->fresh()->available_capacity);
    }

    #[DataProvider('routes')]
    public function test_lower_per_order_limits_do_not_reduce_the_six_ticket_cumulative_allowance(string $route): void
    {
        $event = $this->publishedEvent(['max_per_order' => 2]);
        $buyer = $this->actor();
        $items = [['zoneId' => $event->zones->first()->id, 'quantity' => 2]];
        foreach (['first', 'second', 'third'] as $key) {
            $this->purchase($route, $event, $buyer, $items, $key)->assertCreated();
        }
        $this->purchase($route, $event, $buyer, $items, 'fourth')->assertConflict();
        $this->assertDatabaseCount('tickets', 6);
    }

    public function test_an_identical_idempotent_replay_at_the_limit_does_not_create_more_tickets(): void
    {
        $event = $this->publishedEvent();
        $buyer = $this->actor();
        $items = [['zoneId' => $event->zones->first()->id, 'quantity' => 6]];
        $first = $this->purchase('orders', $event, $buyer, $items, 'same-attempt')->assertCreated()->json();
        $this->purchase('orders', $event, $buyer, $items, 'same-attempt')->assertOk()->assertExactJson($first);
        $this->purchase('orders', $event, $buyer, $items, 'new-attempt')->assertConflict();
        $this->assertDatabaseCount('ticket_orders', 1);
        $this->assertDatabaseCount('tickets', 6);
        $this->assertSame(94, $event->zones->first()->fresh()->available_capacity);
    }
}

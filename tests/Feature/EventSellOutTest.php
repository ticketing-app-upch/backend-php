<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class EventSellOutTest extends TestCase
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
            'orders' => ['orders'],
            'legacy tickets' => ['tickets'],
        ];
    }

    #[DataProvider('purchaseRoutes')]
    public function test_only_the_last_available_place_records_the_milestone(string $route): void
    {
        $event = $this->smallEvent();
        [$first, $second] = $event->zones()->orderBy('id')->get();
        $this->assertNull($event->fresh()->first_sold_out_at);

        $this->buy($route, $event, [['zoneId' => $first->id, 'quantity' => 1]])->assertCreated();
        $this->assertNull($event->fresh()->first_sold_out_at);
        $this->buy($route, $event, [['zoneId' => $first->id, 'quantity' => 1]])->assertCreated();
        $this->assertSame(0, $first->fresh()->available_capacity);
        $this->assertSame(1, $second->fresh()->available_capacity);
        $this->assertNull($event->fresh()->first_sold_out_at);

        $this->travel(2)->hours();
        $expected = now()->toDateTimeString();
        $this->buy($route, $event, [['zoneId' => $second->id, 'quantity' => 1]])->assertCreated();
        $this->assertSame($expected, $event->fresh()->first_sold_out_at?->toDateTimeString());
        $this->assertSame(0, (int) $event->zones()->sum('available_capacity'));
        $this->assertDatabaseCount('tickets', 3);

        $this->travel(1)->hour();
        $this->buy($route, $event, [['zoneId' => $second->id, 'quantity' => 1]])->assertConflict();
        $this->assertSame($expected, $event->fresh()->first_sold_out_at?->toDateTimeString());
        $this->assertDatabaseCount('tickets', 3);
    }

    #[DataProvider('purchaseRoutes')]
    public function test_cancellation_and_a_second_sellout_preserve_the_first_timestamp(string $route): void
    {
        $event = $this->smallEvent();
        $items = $event->zones()->orderBy('id')->get()->map(fn ($zone) => [
            'zoneId' => $zone->id, 'quantity' => $zone->capacity,
        ])->all();
        $this->buy($route, $event, $items)->assertCreated();
        $firstSellOut = $event->fresh()->first_sold_out_at?->toDateTimeString();
        $this->assertNotNull($firstSellOut);
        $order = TicketOrder::where('event_id', $event->id)->firstOrFail();

        $this->travel(1)->hour();
        $this->patchJson('/api/orders/'.$order->id, ['status' => 'CANCELADA'], $this->headersFor($this->actor('ADMIN')))
            ->assertOk();
        $this->assertSame(3, (int) $event->zones()->sum('available_capacity'));
        $this->assertSame($firstSellOut, $event->fresh()->first_sold_out_at?->toDateTimeString());

        $this->travel(1)->hour();
        $this->buy($route, $event, $items)->assertCreated();
        $this->assertSame(0, (int) $event->zones()->sum('available_capacity'));
        $this->assertSame($firstSellOut, $event->fresh()->first_sold_out_at?->toDateTimeString());
        $this->assertDatabaseCount('tickets', 6);
        $this->assertDatabaseCount('ticket_orders', 2);
    }

    #[DataProvider('purchaseRoutes')]
    public function test_failure_after_writing_the_milestone_rolls_back_the_entire_purchase(string $route): void
    {
        $event = $this->smallEvent();
        $items = $event->zones()->orderBy('id')->get()->map(fn ($zone) => [
            'zoneId' => $zone->id, 'quantity' => $zone->capacity,
        ])->all();
        $dispatcher = Event::getEventDispatcher();
        Event::setEventDispatcher(clone $dispatcher);
        $milestoneWasWritten = false;
        $caught = null;
        $this->withoutExceptionHandling();

        try {
            Event::updated(function (Event $updated) use ($event, &$milestoneWasWritten) {
                if ($updated->id === $event->id && $updated->first_sold_out_at !== null) {
                    $milestoneWasWritten = true;
                    throw new \RuntimeException('QA failure after milestone write');
                }
            });
            $this->buy($route, $event, $items);
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        } finally {
            Event::setEventDispatcher($dispatcher);
            $this->withExceptionHandling();
        }

        $this->assertTrue($milestoneWasWritten, 'La prueba debe llegar a escribir el hito antes de fallar.');
        $this->assertNotNull($caught, 'La compra debe propagar el fallo simulado.');
        $this->assertSame('QA failure after milestone write', $caught->getMessage());
        $this->assertNull($event->fresh()->first_sold_out_at);
        $this->assertSame(3, (int) $event->zones()->sum('available_capacity'));
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_orders', 0);
    }

    public function test_idempotent_replay_does_not_change_the_timestamp_or_duplicate_sales(): void
    {
        $event = $this->smallEvent();
        $buyer = $this->actor('CLIENT');
        $items = $event->zones()->orderBy('id')->get()->map(fn ($zone) => [
            'zoneId' => $zone->id, 'quantity' => $zone->capacity,
        ])->all();
        $extra = ['idempotencyKey' => 'qa-sellout-replay'];
        $first = $this->buy('orders', $event, $items, $buyer, $extra)->assertCreated();
        $timestamp = $event->fresh()->first_sold_out_at?->toDateTimeString();
        $this->assertNotNull($timestamp);
        $this->travel(1)->hour();
        $this->buy('orders', $event, $items, $buyer, $extra)->assertOk()
            ->assertJsonPath('id', $first->json('id'));
        $this->assertSame($timestamp, $event->fresh()->first_sold_out_at?->toDateTimeString());
        $this->assertDatabaseCount('tickets', 3);
        $this->assertDatabaseCount('ticket_orders', 1);
    }

    public function test_declined_payment_does_not_record_a_sellout(): void
    {
        $event = $this->smallEvent();
        $items = $event->zones()->orderBy('id')->get()->map(fn ($zone) => [
            'zoneId' => $zone->id, 'quantity' => $zone->capacity,
        ])->all();
        $this->buy('orders', $event, $items, extra: ['paymentResult' => 'DECLINED'])->assertStatus(402);
        $this->assertNull($event->fresh()->first_sold_out_at);
        $this->assertSame(3, (int) $event->zones()->sum('available_capacity'));
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_orders', 0);
    }

    public function test_client_cannot_supply_the_milestone_timestamp(): void
    {
        $event = $this->smallEvent();
        $items = $event->zones()->orderBy('id')->get()->map(fn ($zone) => [
            'zoneId' => $zone->id, 'quantity' => $zone->capacity,
        ])->all();
        $expected = now()->toDateTimeString();
        $this->buy('orders', $event, $items, extra: [
            'first_sold_out_at' => '2000-01-01 00:00:00',
            'firstSoldOutAt' => '2000-01-01T00:00:00Z',
        ])->assertCreated();
        $this->assertSame($expected, $event->fresh()->first_sold_out_at?->toDateTimeString());
    }

    private function smallEvent(): Event
    {
        $event = $this->publishedEvent(['published_at' => now()->subHours(4)]);
        $event->zones()->delete();
        foreach ([2, 1] as $index => $capacity) {
            $event->zones()->create([
                'name' => 'Zona QA '.($index + 1), 'capacity' => $capacity,
                'available_capacity' => $capacity, 'base_price' => 50,
            ]);
        }
        $event->update(['venue_capacity' => 3]);

        return $event;
    }

    private function buy(string $route, Event $event, array $items, ?User $buyer = null, array $extra = []): TestResponse
    {
        $payload = ['items' => $items];
        if ($route === 'orders') {
            $url = '/api/orders';
            $payload['eventId'] = $event->id;
        } else {
            $url = '/api/events/'.$event->id.'/tickets';
            $payload['payment'] = $this->payment();
        }

        return $this->postJson($url, array_merge($payload, $extra), $this->headersFor($buyer ?? $this->actor('CLIENT')));
    }
}

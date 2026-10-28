<?php

namespace Tests\Feature;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class PurchaseTicketTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_multi_zone_purchase_emits_one_unique_ticket_per_entry_and_deducts_correct_stock(): void
    {
        $event = $this->publishedEvent();
        [$first, $second] = $event->zones;
        $second->update(['base_price' => 75.25]);
        $response = $this->postJson('/api/events/'.$event->id.'/tickets', [
            'items' => [['zoneId' => $first->id, 'quantity' => 2], ['zoneId' => $second->id, 'quantity' => 1]],
            'payment' => $this->payment(),
        ], $this->headersFor($this->actor()))->assertCreated()
            ->assertJsonPath('paymentStatus', 'APPROVED')->assertJsonPath('quantity', 3)
            ->assertJsonPath('totalPrice', 175.25)->assertJsonCount(3, 'tickets');

        $codes = array_column($response->json('tickets'), 'code');
        $this->assertCount(3, array_unique($codes));
        foreach ($response->json('tickets') as $ticket) {
            $this->assertSame(1, $ticket['quantity']);
            $this->assertMatchesRegularExpression('/\ATICK-2026-[A-F0-9]{16}\z/', $ticket['code']);
            $this->assertSame($ticket['validationUrl'], $ticket['qrValue']);
            $this->assertStringEndsWith('/validar/'.$ticket['code'], $ticket['validationUrl']);
        }
        $this->assertSame(98, $first->fresh()->available_capacity);
        $this->assertSame(199, $second->fresh()->available_capacity);
        $this->assertDatabaseCount('tickets', 3);
        $response->assertJsonMissingPath('payment')->assertJsonMissingPath('tickets.0.user_id');
    }

    public function test_single_zone_payload_is_supported_and_maximum_six_is_accepted(): void
    {
        $event = $this->publishedEvent();
        $this->postJson('/api/events/'.$event->id.'/tickets', [
            'zoneId' => $event->zones->first()->id, 'quantity' => 6, 'payment' => $this->payment(),
        ], $this->headersFor($this->actor()))->assertCreated()->assertJsonCount(6, 'tickets');
    }

    public function test_client_cannot_override_prices_or_buyer_identity(): void
    {
        $event = $this->publishedEvent();
        $buyer = $this->actor();
        $other = $this->actor();
        $response = $this->postJson('/api/events/'.$event->id.'/tickets', [
            'zoneId' => $event->zones->first()->id, 'quantity' => 1,
            'payment' => $this->payment(), 'totalPrice' => 0, 'userId' => $other->id,
            'unitPrice' => 0, 'code' => 'MY-CODE',
        ], $this->headersFor($buyer))->assertCreated()->assertJsonPath('totalPrice', 50);
        $this->assertDatabaseHas('tickets', ['id' => $response->json('tickets.0.id'), 'user_id' => $buyer->id]);
        $this->assertNotSame('MY-CODE', $response->json('tickets.0.code'));
    }

    public function test_seven_total_entries_are_rejected_even_across_zones(): void
    {
        $event = $this->publishedEvent();
        [$first, $second] = $event->zones;
        $this->postJson('/api/events/'.$event->id.'/tickets', [
            'items' => [['zoneId' => $first->id, 'quantity' => 4], ['zoneId' => $second->id, 'quantity' => 3]],
            'payment' => $this->payment(),
        ], $this->headersFor($this->actor()))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('tickets', 0);
        $this->assertSame(100, $first->fresh()->available_capacity);
    }

    public function test_insufficient_capacity_returns_409_without_partial_deductions(): void
    {
        $event = $this->publishedEvent();
        [$first, $second] = $event->zones;
        $second->update(['available_capacity' => 0]);
        $this->postJson('/api/events/'.$event->id.'/tickets', [
            'items' => [['zoneId' => $first->id, 'quantity' => 1], ['zoneId' => $second->id, 'quantity' => 1]],
            'payment' => $this->payment(),
        ], $this->headersFor($this->actor()))->assertConflict()
            ->assertExactJson(['message' => 'Aforo insuficiente para la cantidad solicitada']);
        $this->assertSame(100, $first->fresh()->available_capacity);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_sequential_buyers_cannot_oversell_last_place(): void
    {
        $event = $this->publishedEvent();
        $zone = $event->zones->first();
        $zone->update(['available_capacity' => 1]);
        $payload = ['zoneId' => $zone->id, 'quantity' => 1, 'payment' => $this->payment()];
        $this->postJson('/api/events/'.$event->id.'/tickets', $payload, $this->headersFor($this->actor()))->assertCreated();
        $this->postJson('/api/events/'.$event->id.'/tickets', $payload, $this->headersFor($this->actor()))->assertConflict();
        $this->assertSame(0, $zone->fresh()->available_capacity);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_buying_revalidates_the_account_even_with_a_previously_valid_token(): void
    {
        $event = $this->publishedEvent();
        $client = $this->actor();
        $headers = $this->headersFor($client);
        $client->update(['active' => false]);
        $this->postJson('/api/events/'.$event->id.'/tickets', [
            'zoneId' => $event->zones->first()->id, 'quantity' => 1, 'payment' => $this->payment(),
        ], $headers)->assertForbidden();
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_purchase_requires_client_role_and_authentication(): void
    {
        $event = $this->publishedEvent();
        $url = '/api/events/'.$event->id.'/tickets';
        $this->postJson($url)->assertUnauthorized();
        foreach (['ORGANIZER', 'ADMIN'] as $role) {
            $this->postJson($url, [], $this->headersFor($this->actor($role)))->assertForbidden();
        }
    }

    public function test_cannot_buy_past_hidden_unknown_or_foreign_zone_events(): void
    {
        $event = $this->publishedEvent();
        $client = $this->actor();
        $payload = ['zoneId' => $event->zones->first()->id, 'quantity' => 1, 'payment' => $this->payment()];
        foreach ([['status' => 'DRAFT'], ['starts_at' => now()->subDay()]] as $changes) {
            $other = $this->publishedEvent($changes);
            $this->postJson('/api/events/'.$other->id.'/tickets', $payload, $this->headersFor($client))->assertNotFound();
        }
        $this->postJson('/api/events/999999/tickets', $payload, $this->headersFor($client))->assertNotFound();
        $other = $this->publishedEvent();
        $this->postJson('/api/events/'.$other->id.'/tickets', $payload, $this->headersFor($client))->assertUnprocessable();
        $this->assertDatabaseCount('tickets', 0);
    }

    public static function invalidPayments(): array
    {
        return [
            'non digits' => ['cardNumber', '4111 1111 1111 1111'],
            'too short card' => ['cardNumber', '4111'],
            'numeric card' => ['cardNumber', 4111111111111111],
            'bad cvv' => ['cvv', 'abc'],
            'short cvv' => ['cvv', '12'],
            'expired date' => ['expiresAt', '2026-10-24'],
            'today' => ['expiresAt', '2026-10-25'],
            'bad date' => ['expiresAt', '31/12/2027'],
        ];
    }

    #[DataProvider('invalidPayments')]
    public function test_payment_formats_are_validated_before_stock_changes(string $field, mixed $value): void
    {
        $event = $this->publishedEvent();
        $zone = $event->zones->first();
        $this->postJson('/api/events/'.$event->id.'/tickets', [
            'zoneId' => $zone->id, 'quantity' => 1, 'payment' => $this->payment([$field => $value]),
        ], $this->headersFor($this->actor()))->assertUnprocessable()->assertJsonValidationErrors('payment.'.$field);
        $this->assertSame(100, $zone->fresh()->available_capacity);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_missing_payment_duplicate_zones_and_ambiguous_payload_are_rejected(): void
    {
        $event = $this->publishedEvent();
        $zone = $event->zones->first();
        $client = $this->actor();
        foreach ([
            ['zoneId' => $zone->id, 'quantity' => 1],
            ['items' => [['zoneId' => $zone->id, 'quantity' => 1], ['zoneId' => $zone->id, 'quantity' => 1]], 'payment' => $this->payment()],
            ['zoneId' => $zone->id, 'quantity' => 1, 'items' => [['zoneId' => $zone->id, 'quantity' => 1]], 'payment' => $this->payment()],
            ['zoneId' => $zone->id, 'quantity' => 0, 'payment' => $this->payment()],
        ] as $payload) {
            $this->postJson('/api/events/'.$event->id.'/tickets', $payload, $this->headersFor($client))->assertUnprocessable();
        }
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_failure_while_issuing_tickets_rolls_back_all_stock_and_entries(): void
    {
        $event = $this->publishedEvent();
        $zone = $event->zones->first();
        $dispatcher = Ticket::getEventDispatcher();
        Ticket::setEventDispatcher(clone $dispatcher);
        $created = 0;
        Ticket::creating(function () use (&$created) {
            if (++$created === 2) {
                throw new \RuntimeException('Simulated ticket failure');
            }
        });
        try {
            $this->withoutExceptionHandling();
            $this->postJson('/api/events/'.$event->id.'/tickets', [
                'zoneId' => $zone->id, 'quantity' => 2, 'payment' => $this->payment(),
            ], $this->headersFor($this->actor()));
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated ticket failure', $exception->getMessage());
            $this->assertDatabaseCount('tickets', 0);
            $this->assertSame(100, $zone->fresh()->available_capacity);
        } finally {
            Ticket::setEventDispatcher($dispatcher);
        }
    }
}

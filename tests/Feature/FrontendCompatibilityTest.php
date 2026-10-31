<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class FrontendCompatibilityTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function frontendEvent(array $overrides = []): array
    {
        $venue = Venue::with('zones')->where('slug', 'arena-1')->firstOrFail();
        $bytes = file_get_contents(base_path('tests/Fixtures/banner.jpg'));

        return array_replace([
            'id' => '', 'name' => 'Evento compatible Demo 3', 'description' => 'Descripción completa del evento de prueba.',
            'category' => 'CONCIERTO', 'status' => 'PUBLICADO', 'venue' => $venue->name, 'city' => $venue->city,
            'startsAt' => now()->addMonth()->toIso8601String(), 'publishedAt' => now()->toIso8601String(),
            'venueCapacity' => $venue->capacity, 'organizerId' => 'untrusted', 'maxPerOrder' => 6,
            'imageUrl' => '/mock/event.jpg', 'bannerUrl' => 'data:image/jpeg;base64,'.base64_encode($bytes),
            'secondaryBannerUrl' => 'data:image/jpeg;base64,'.base64_encode($bytes."\nsecondary"),
            'zones' => $venue->zones->map(fn ($zone, $index) => [
                'id' => 'venue-arena-1-'.$index, 'name' => $zone->name,
                'capacity' => $zone->capacity, 'sold' => 0, 'price' => 50,
            ])->all(),
        ], $overrides);
    }

    private function orderPayload(Event $event, array $overrides = []): array
    {
        return array_replace([
            'eventId' => (string) $event->id,
            'items' => [['zoneId' => (string) $event->zones->first()->id, 'quantity' => 1]],
            'expectedTotal' => 50, 'paymentMethod' => 'CARD', 'paymentResult' => 'APPROVED',
            'idempotencyKey' => 'attempt-1',
        ], $overrides);
    }

    public function test_json_event_creation_preserves_both_images_and_server_owned_fields(): void
    {
        $owner = $this->actor('ORGANIZER');
        $payload = $this->frontendEvent();
        $response = $this->postJson('/api/events', $payload, $this->headersFor($owner))->assertCreated()
            ->assertJsonPath('name', $payload['name'])->assertJsonPath('status', 'PUBLICADO')
            ->assertJsonPath('venue', 'Arena 1 — Costa Verde')->assertJsonPath('city', 'Lima')
            ->assertJsonPath('organizerId', (string) $owner->id)->assertJsonPath('maxPerOrder', 6)
            ->assertJsonPath('zones.0.price', 50)->assertJsonPath('zones.0.sold', 0);
        $id = $response->json('id');
        $this->get('/api/events/'.$id.'/banner')->assertOk()
            ->assertContent(base64_decode(substr($payload['bannerUrl'], 23)));
        $this->get('/api/events/'.$id.'/secondary-banner')->assertOk()
            ->assertContent(base64_decode(substr($payload['secondaryBannerUrl'], 23)));
        DB::enableQueryLog();
        $this->getJson('/api/events')->assertOk()->assertJsonPath('0.id', $id)
            ->assertJsonMissingPath('0.content')->assertJsonMissingPath('0.secondary_content');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertFalse(collect($queries)->contains(fn ($query) => preg_match('/^select \* from ["`]?event_banners/i', $query['query'])));
        $this->getJson('/api/events/'.$id.'?contract=legacy')->assertOk()
            ->assertJsonPath('status', 'PUBLISHED')->assertJsonPath('venue.name', $payload['venue']);
    }

    public function test_single_zone_test_venue_can_be_published_read_publicly_and_sold_out(): void
    {
        $venue = Venue::with('zones')->where('slug', 'recinto-prueba')->sole();
        $payload = $this->frontendEvent([
            'venue' => $venue->name, 'city' => $venue->city, 'venueCapacity' => 1,
            'zones' => [['id' => 'venue-recinto-prueba-0', 'name' => 'General',
                'capacity' => 1, 'sold' => 0, 'price' => 50]],
        ]);
        $created = $this->postJson('/api/events', $payload, $this->headersFor($this->actor('ORGANIZER')))
            ->assertCreated()->assertJsonPath('venue', 'Recinto de prueba')
            ->assertJsonPath('venueCapacity', 1)->assertJsonCount(1, 'zones')
            ->assertJsonPath('zones.0.name', 'General')->assertJsonPath('zones.0.capacity', 1);
        $event = Event::with('zones')->findOrFail($created->json('id'));

        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->getJson('/api/events/'.$event->id)->assertOk()
            ->assertJsonPath('status', 'PUBLICADO')->assertJsonPath('zones.0.availableCapacity', 1);

        $this->postJson('/api/orders', $this->orderPayload($event), $this->headersFor($this->actor()))
            ->assertCreated()->assertJsonCount(1, 'tickets');
        $this->postJson('/api/orders', $this->orderPayload($event), $this->headersFor($this->actor()))
            ->assertConflict()->assertJsonPath('message', 'Aforo insuficiente para la cantidad solicitada');
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('ticket_orders', 1);
        $this->assertSame(0, $event->zones->sole()->fresh()->available_capacity);
        $this->getJson('/api/events/'.$event->id)->assertOk()->assertJsonPath('status', 'AGOTADO');
    }

    public function test_single_zone_payload_cannot_omit_other_zones_of_a_larger_venue(): void
    {
        $payload = $this->frontendEvent();
        $payload['zones'] = [$payload['zones'][0]];
        $this->postJson('/api/events', $payload, $this->headersFor($this->actor('ORGANIZER')))
            ->assertUnprocessable()->assertJsonValidationErrors('zones')
            ->assertJsonPath('errors.zones.0', 'Las zonas y aforos deben coincidir exactamente con el recinto.');
        $this->assertDatabaseCount('events', 0);
    }

    public static function invalidFrontendEvents(): array
    {
        return [
            'wrong city' => ['city', 'Otra ciudad', 'venue'],
            'wrong venue capacity' => ['venueCapacity', 999999, 'venue'],
            'wrong zone capacity' => ['zones.0.capacity', 999999, 'zones'],
            'foreign zone name' => ['zones.0.name', 'Zona ajena', 'zones'],
            'sold stock injection' => ['zones.0.sold', 1, 'zones.0.sold'],
            'historical sales injection' => ['zones.0.openingSold', 20, 'zones.0.openingSold'],
            'invalid price' => ['zones.0.price', 0, 'zones.0.price'],
            'invalid jpeg' => ['bannerUrl', 'data:image/jpeg;base64,YWJj', 'bannerUrl'],
            'remote image' => ['secondaryBannerUrl', 'https://example.test/image.jpg', 'secondaryBannerUrl'],
            'existing event id' => ['id', '1', 'id'],
            'no zones' => ['zones', [], 'zones'],
        ];
    }

    #[DataProvider('invalidFrontendEvents')]
    public function test_frontend_event_rejects_invalid_or_tampered_data_without_partial_writes(string $field, mixed $value, string $error): void
    {
        $payload = $this->frontendEvent();
        data_set($payload, $field, $value);
        $this->postJson('/api/events', $payload, $this->headersFor($this->actor('ORGANIZER')))
            ->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('event_banners', 0);
    }

    public function test_json_images_must_be_distinct_and_have_real_jpeg_dimensions(): void
    {
        $payload = $this->frontendEvent();
        $payload['secondaryBannerUrl'] = $payload['bannerUrl'];
        $headers = $this->headersFor($this->actor('ORGANIZER'));
        $this->postJson('/api/events', $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors('secondaryBannerUrl');
        $payload = $this->frontendEvent(['bannerUrl' => 'data:image/jpeg;base64,'.base64_encode(file_get_contents(base_path('tests/Fixtures/small-banner.jpg')))]);
        $this->postJson('/api/events', $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors('bannerUrl');
    }

    public function test_json_creation_requires_an_active_organizer_and_uses_the_frontends_24_hour_rule(): void
    {
        $payload = $this->frontendEvent(['startsAt' => '2026-11-26T04:00:00Z']);
        $this->postJson('/api/events', $payload)->assertUnauthorized();
        $this->postJson('/api/events', $payload, $this->headersFor($this->actor()))->assertForbidden();
        $this->postJson('/api/events', $payload, $this->headersFor($this->actor('ORGANIZER', false)))->assertForbidden();
        $headers = $this->headersFor($this->actor('ORGANIZER'));
        $this->postJson('/api/events', $payload, $headers)->assertCreated();
        $payload['startsAt'] = '2026-11-26T06:00:00Z';
        $this->postJson('/api/events', $payload, $headers)->assertConflict();
        $payload['startsAt'] = '2026-11-27T04:00:00Z';
        $this->postJson('/api/events', $payload, $headers)->assertCreated();
    }

    public function test_catalog_supports_frontend_fields_search_and_availability(): void
    {
        $sold = $this->publishedEvent(['title' => 'Evento agotado', 'city' => 'Cusco']);
        $sold->zones()->update(['available_capacity' => 0]);
        $available = $this->publishedEvent(['title' => 'Festival Lima', 'city' => 'Lima']);
        $this->getJson('/api/events?search=CUSCO')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Evento agotado')->assertJsonPath('0.status', 'AGOTADO')
            ->assertJsonPath('0.zones.0.sold', 100);
        $this->getJson('/api/events?available=true')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', (string) $available->id);
        $this->getJson('/api/events?search=Festival&category=concierto&available=true')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/events?search=%25')->assertOk()->assertExactJson([]);
        $this->getJson('/api/events?available=invalid')->assertUnprocessable();
    }

    public function test_organizer_and_admin_event_routes_enforce_ownership(): void
    {
        $owner = $this->actor('ORGANIZER');
        $this->publishedEvent(['organizer_id' => $owner->id]);
        $this->publishedEvent(['status' => 'DRAFT']);
        $url = '/api/organizers/'.$owner->id.'/events';
        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, $this->headersFor($owner))->assertOk()->assertJsonCount(1);
        $this->getJson($url, $this->headersFor($this->actor('ORGANIZER')))->assertForbidden();
        $admin = $this->actor('ADMIN');
        $this->getJson($url, $this->headersFor($admin))->assertOk()->assertJsonCount(1);
        $this->getJson('/api/admin/events', $this->headersFor($admin))->assertOk()->assertJsonCount(2);
        $this->getJson('/api/admin/events', $this->headersFor($owner))->assertForbidden();
    }

    public function test_order_uses_final_server_prices_without_fees_and_emits_individual_tickets(): void
    {
        $event = $this->publishedEvent(['city' => 'Lima']);
        [$first, $second] = $event->zones;
        $second->update(['base_price' => 75.25]);
        $buyer = $this->actor();
        $payload = $this->orderPayload($event, [
            'items' => [['zoneId' => (string) $first->id, 'quantity' => 2], ['zoneId' => (string) $second->id, 'quantity' => 1]],
            'expectedTotal' => 175.25, 'buyerId' => 'another-user', 'unitPrice' => 0,
        ]);
        $response = $this->postJson('/api/orders', $payload, $this->headersFor($buyer))->assertCreated()
            ->assertJsonPath('buyerId', (string) $buyer->id)->assertJsonPath('status', 'CONFIRMADA')
            ->assertJsonPath('subtotal', 175.25)->assertJsonPath('fee', 0)->assertJsonPath('total', 175.25)
            ->assertJsonCount(2, 'lines')->assertJsonCount(3, 'tickets');
        $this->assertEqualsWithDelta(175.25, array_sum(array_column($response->json('tickets'), 'total')), 0.00001);
        $this->assertEquals(0, array_sum(array_column($response->json('tickets'), 'serviceFee')));
        $this->assertCount(3, array_unique(array_column($response->json('tickets'), 'code')));
        $this->assertSame(98, $first->fresh()->available_capacity);
        $this->assertSame(199, $second->fresh()->available_capacity);
        $this->assertDatabaseCount('tickets', 3);
        $this->getJson('/api/v1/tickets', $this->headersFor($buyer))->assertOk()->assertJsonCount(3);
        $this->getJson('/api/v1/tickets/'.$response->json('tickets.0.code').'/validacion')->assertOk();
    }

    public function test_small_prices_keep_exact_totals_without_service_fees(): void
    {
        $event = $this->publishedEvent();
        $event->zones->first()->update(['base_price' => 0.10]);
        $response = $this->postJson('/api/orders', $this->orderPayload($event, [
            'items' => [['zoneId' => (string) $event->zones->first()->id, 'quantity' => 6]], 'expectedTotal' => 0.60,
        ]), $this->headersFor($this->actor()))->assertCreated()
            ->assertJsonPath('subtotal', 0.6)->assertJsonPath('fee', 0)->assertJsonPath('total', 0.6);
        $this->assertEqualsWithDelta(0.60, array_sum(array_column($response->json('tickets'), 'total')), 0.00001);
        $this->assertEqualsWithDelta(0, array_sum(array_column($response->json('tickets'), 'serviceFee')), 0.00001);
    }

    public function test_idempotency_replays_without_stock_changes_and_rejects_a_different_payload(): void
    {
        $event = $this->publishedEvent();
        $buyer = $this->actor();
        $payload = $this->orderPayload($event);
        $first = $this->postJson('/api/orders', $payload, $this->headersFor($buyer))->assertCreated()->json();
        $this->postJson('/api/orders', $payload, $this->headersFor($buyer))->assertOk()->assertExactJson($first);
        $payload['items'][0]['quantity'] = 2;
        $this->postJson('/api/orders', $payload, $this->headersFor($buyer))->assertConflict();
        $this->assertDatabaseCount('ticket_orders', 1);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertSame(99, $event->zones->first()->fresh()->available_capacity);
        $this->postJson('/api/orders', $this->orderPayload($event), $this->headersFor($this->actor()))->assertCreated();
    }

    public function test_existing_orders_keep_historical_fees_on_read_and_idempotent_replay(): void
    {
        $event = $this->publishedEvent();
        $buyer = $this->actor();
        $payload = $this->orderPayload($event);
        // Simula una compra histórica cuyo total confirmado incluía el recargo.
        $payload['expectedTotal'] = 53;
        $items = [['zoneId' => (int) $event->zones->first()->id, 'quantity' => 1]];
        $historicalHash = hash('sha256', json_encode([
            'eventId' => (int) $event->id, 'items' => $items, 'expectedTotal' => 5300,
            'paymentMethod' => 'CARD', 'paymentResult' => 'APPROVED',
        ], JSON_THROW_ON_ERROR));
        $createPayload = array_replace($payload, ['expectedTotal' => 50]);
        $order = $this->postJson('/api/orders', $createPayload, $this->headersFor($buyer))->assertCreated()->json();
        DB::table('ticket_orders')->where('id', $order['id'])->update([
            'fee' => 3, 'total' => 53, 'request_hash' => $historicalHash,
        ]);
        DB::table('tickets')->where('ticket_order_id', $order['id'])->update(['service_fee' => 3, 'total_price' => 53]);
        $this->getJson('/api/orders/'.$order['id'], $this->headersFor($buyer))->assertOk()
            ->assertJsonPath('fee', 3)->assertJsonPath('total', 53)
            ->assertJsonPath('tickets.0.serviceFee', 3)->assertJsonPath('tickets.0.total', 53);
        $this->postJson('/api/orders', $payload, $this->headersFor($buyer))->assertOk()
            ->assertJsonPath('id', $order['id'])->assertJsonPath('fee', 3)->assertJsonPath('total', 53);
        $this->assertSame(99, $event->zones->first()->fresh()->available_capacity);
        $this->assertDatabaseCount('ticket_orders', 1);
    }

    public function test_declined_payment_price_conflicts_and_insufficient_stock_leave_no_partial_purchase(): void
    {
        $event = $this->publishedEvent();
        $headers = $this->headersFor($this->actor());
        $this->postJson('/api/orders', $this->orderPayload($event, ['paymentResult' => 'DECLINED']), $headers)->assertStatus(402);
        $this->postJson('/api/orders', $this->orderPayload($event, ['expectedTotal' => 1]), $headers)->assertConflict();
        $this->postJson('/api/orders', $this->orderPayload($event, ['expectedTotal' => 53]), $headers)
            ->assertConflict()->assertJsonPath('message', 'El precio cambió. Solicita una nueva cotización antes de confirmar.');
        $event->zones->first()->update(['available_capacity' => 0]);
        $this->postJson('/api/orders', $this->orderPayload($event), $headers)->assertConflict();
        $this->assertDatabaseCount('ticket_orders', 0);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_orders_validate_limits_zones_roles_and_account_state(): void
    {
        $event = $this->publishedEvent(['max_per_order' => 2]);
        $payload = $this->orderPayload($event);
        $this->postJson('/api/orders', $payload)->assertUnauthorized();
        $this->postJson('/api/orders', $payload, $this->headersFor($this->actor('ORGANIZER')))->assertForbidden();
        $this->postJson('/api/orders', $payload, $this->headersFor($this->actor('ADMIN')))->assertForbidden();
        $this->postJson('/api/orders', $payload, $this->headersFor($this->actor('CLIENT', false)))->assertForbidden();
        $headers = $this->headersFor($this->actor());
        $payload['items'][0]['quantity'] = 3;
        $this->postJson('/api/orders', $payload, $headers)->assertUnprocessable();
        $this->postJson('/api/events/'.$event->id.'/tickets', [
            'zoneId' => $event->zones->first()->id, 'quantity' => 3, 'payment' => $this->payment(),
        ], $headers)->assertUnprocessable();
        $payload['items'][0]['quantity'] = 7;
        $this->postJson('/api/orders', $payload, $headers)->assertUnprocessable();
        $payload = $this->orderPayload($event);
        $payload['items'][] = $payload['items'][0];
        $this->postJson('/api/orders', $payload, $headers)->assertUnprocessable();
        $payload = $this->orderPayload($event, ['items' => [['zoneId' => $this->publishedEvent()->zones->first()->id, 'quantity' => 1]]]);
        $this->postJson('/api/orders', $payload, $headers)->assertUnprocessable();
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_orders_are_private_and_keep_immutable_purchase_snapshots(): void
    {
        $event = $this->publishedEvent();
        $buyer = $this->actor();
        $order = $this->postJson('/api/orders', $this->orderPayload($event), $this->headersFor($buyer))->assertCreated()->json();
        $this->getJson('/api/orders/me', $this->headersFor($buyer))->assertOk()->assertJsonCount(1);
        $other = $this->actor();
        $this->getJson('/api/orders/me', $this->headersFor($other))->assertOk()->assertExactJson([]);
        $this->getJson('/api/orders/'.$order['id'], $this->headersFor($other))->assertNotFound();
        DB::table('events')->where('id', $event->id)->update(['title' => 'Nuevo título']);
        DB::table('users')->where('id', $buyer->id)->update(['full_name' => 'Nuevo nombre']);
        $this->getJson('/api/orders/'.$order['id'], $this->headersFor($buyer))->assertOk()->assertExactJson($order);
        $this->getJson('/api/admin/orders', $this->headersFor($this->actor('ADMIN')))->assertOk()->assertJsonCount(1);
        $this->expectException(\LogicException::class);
        TicketOrder::findOrFail($order['id'])->update(['total' => 1]);
    }

    public function test_cancellation_restores_stock_once_invalidates_tickets_and_updates_metrics(): void
    {
        $event = $this->publishedEvent();
        $buyer = $this->actor();
        $order = $this->postJson('/api/orders', $this->orderPayload($event), $this->headersFor($buyer))->assertCreated()->json();
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'CANCELADA'], $this->headersFor($buyer))->assertForbidden();
        $admin = $this->actor('ADMIN');
        $this->getJson('/api/admin/metrics', $this->headersFor($admin))->assertOk()->assertJsonPath('grossRevenue', 50);
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'CANCELADA'], $this->headersFor($admin))
            ->assertOk()->assertJsonPath('status', 'CANCELADA');
        $this->assertSame(100, $event->zones->first()->fresh()->available_capacity);
        $this->getJson('/api/v1/tickets/'.$order['tickets'][0]['code'].'/validacion')->assertConflict();
        $this->patchJson('/api/orders/'.$order['id'], ['status' => 'CANCELADA'], $this->headersFor($admin))->assertConflict();
        $this->getJson('/api/admin/metrics', $this->headersFor($admin))->assertOk()
            ->assertJsonPath('grossRevenue', 0)->assertJsonPath('orders.cancelled', 1)->assertJsonPath('ticketsSold', 0);
    }

    public function test_event_deletion_requires_ownership_and_preserves_all_purchase_history(): void
    {
        $event = $this->publishedEvent();
        $this->deleteJson('/api/events/'.$event->id, [], $this->headersFor($this->actor('ORGANIZER')))->assertForbidden();
        $this->postJson('/api/orders', $this->orderPayload($event), $this->headersFor($this->actor()))->assertCreated();
        $this->deleteJson('/api/events/'.$event->id, [], $this->headersFor($event->organizer))->assertConflict();
        $empty = $this->publishedEvent();
        $this->deleteJson('/api/events/'.$empty->id, [], $this->headersFor($empty->organizer))->assertNoContent();
        $this->assertDatabaseMissing('events', ['id' => $empty->id]);
    }

    public function test_admin_user_aliases_preserve_the_existing_permissions_and_status_contract(): void
    {
        $client = $this->actor();
        $admin = $this->actor('ADMIN');
        $this->getJson('/api/admin/users', $this->headersFor($client))->assertForbidden();
        $this->getJson('/api/admin/users', $this->headersFor($admin))->assertOk()->assertJsonCount(1);
        $this->patchJson('/api/admin/users/'.$client->id, ['active' => false], $this->headersFor($admin))
            ->assertOk()->assertJsonPath('active', false);
        $this->patchJson('/api/admin/users/'.$client->id, ['role' => 'ADMIN'], $this->headersFor($admin))->assertUnprocessable();
        $this->patchJson('/api/admin/users/'.$admin->id, ['active' => false], $this->headersFor($admin))->assertForbidden();
    }

    public function test_base_pricing_uses_persisted_prices_and_exposes_both_frontend_paths(): void
    {
        $event = $this->publishedEvent();
        $payload = ['id_zona' => (string) $event->zones->first()->id, 'precio_base' => 0.01, 'aforo_disponible' => 999999];
        foreach (['/api/v1/dynamic-price', '/pricing/api/v1/dynamic-price'] as $path) {
            $this->postJson($path, $payload)->assertOk()->assertExactJson(['precio_ajustado' => 50, 'motivo' => 'Precio base']);
        }
        $hidden = $this->publishedEvent(['status' => 'DRAFT']);
        $this->postJson('/api/v1/dynamic-price', ['id_zona' => $hidden->zones->first()->id])->assertNotFound();
        $this->postJson('/api/v1/dynamic-price', [])->assertUnprocessable();
    }

    public function test_frontend_registration_ignores_extra_fields_without_exposing_internal_fields(): void
    {
        $this->postJson('/api/auth/register', [
            'fullName' => 'Cliente Demo', 'email' => 'cliente@example.test', 'password' => 'TestPass123!',
            'role' => 'CLIENT', 'acceptedTerms' => true, 'marketingOptIn' => false,
            'profile' => ['docType' => 'DNI', 'docNumber' => '12345678', 'country' => 'Perú', 'city' => 'Lima',
                'district' => '', 'hasPeruvianNationality' => false, 'gender' => 'F', 'phoneCode' => '+51', 'phone' => '987654321',
                'role' => 'ADMIN'],
        ])->assertCreated()->assertJsonPath('user.profile.docNumber', '12345678')
            ->assertJsonMissingPath('user.profile.city')->assertJsonMissingPath('user.marketingOptIn')
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.role_id')
            ->assertJsonMissingPath('user.profile.role')->assertJsonMissingPath('user.active');
        $this->postJson('/api/auth/login', ['email' => 'cliente@example.test', 'password' => 'TestPass123!', 'remember' => true])
            ->assertOk()->assertJsonPath('user.profile.docNumber', '12345678')
            ->assertJsonMissingPath('user.profile.phone')->assertJsonMissingPath('user.marketingOptIn');
    }

    public function test_order_issuance_failure_rolls_back_order_tickets_and_capacity(): void
    {
        $event = $this->publishedEvent();
        $payload = $this->orderPayload($event, [
            'items' => [['zoneId' => $event->zones->first()->id, 'quantity' => 2]], 'expectedTotal' => 100,
        ]);
        $dispatcher = Ticket::getEventDispatcher();
        Ticket::setEventDispatcher(clone $dispatcher);
        $created = 0;
        Ticket::creating(function () use (&$created) {
            if (++$created === 2) {
                throw new \RuntimeException('Simulated order issuance failure');
            }
        });
        try {
            $this->withoutExceptionHandling();
            $this->postJson('/api/orders', $payload, $this->headersFor($this->actor()));
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated order issuance failure', $exception->getMessage());
            $this->assertDatabaseCount('ticket_orders', 0);
            $this->assertDatabaseCount('tickets', 0);
            $this->assertSame(100, $event->zones->first()->fresh()->available_capacity);
        } finally {
            Ticket::setEventDispatcher($dispatcher);
        }
    }

    public function test_migration_preserves_historical_ticket_identity_amount_and_stock_as_an_order(): void
    {
        $event = $this->publishedEvent();
        $zone = $event->zones->first();
        $zone->update(['available_capacity' => 99]);
        $buyer = $this->actor();
        $ticket = Ticket::create([
            'user_id' => $buyer->id, 'event_zone_id' => $zone->id, 'quantity' => 1,
            'total_price' => 50, 'unit_price' => 50, 'code' => 'legacy-existing-code',
            'event_title' => $event->title, 'event_starts_at' => $event->starts_at,
            'venue_name' => $event->venue_name, 'venue_address' => $event->venue_address, 'zone_name' => $zone->name,
        ]);
        $migration = require database_path('migrations/2026_10_28_000001_add_frontend_contract_support.php');
        $migration->down();
        $migration->up();
        $this->assertSame(99, $zone->fresh()->available_capacity);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'code' => 'legacy-existing-code', 'total_price' => 50]);
        $this->getJson('/api/orders/me', $this->headersFor($buyer))->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.total', 50)->assertJsonPath('0.fee', 0)
            ->assertJsonPath('0.tickets.0.code', 'legacy-existing-code');
    }
}

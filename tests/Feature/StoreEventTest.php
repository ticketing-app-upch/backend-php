<?php

namespace Tests\Feature;

use App\Models\EventBanner;
use App\Models\Venue;
use Database\Seeders\VenueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class StoreEventTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_venues_are_seeded_idempotently_with_capacities_and_renderable_maps(): void
    {
        $this->seed(VenueSeeder::class);
        $this->assertDatabaseCount('venues', 15);
        $this->assertDatabaseCount('venue_zones', 50);
        $response = $this->getJson('/api/v1/recintos', $this->headersFor($this->actor('ORGANIZER')))
            ->assertOk()->assertJsonCount(15)->assertJsonPath('0.map.viewBox', '0 0 1000 620');
        foreach ($response->json() as $venue) {
            $this->assertSame($venue['capacity'], array_sum(array_column($venue['zones'], 'capacity')));
            $this->assertSame('rect', $venue['zones'][0]['shape']['type']);
        }
    }

    public function test_event_is_created_pending_with_all_zones_and_banners(): void
    {
        $organizer = $this->actor('ORGANIZER');
        $venue = Venue::with('zones')->firstOrFail();

        $response = $this->postJson(
            '/api/events',
            $this->eventPayload(),
            $this->headersFor($organizer),
        )->assertCreated()
            ->assertJsonPath('status', 'PENDING_APPROVAL')
            ->assertJsonPath('queueEnabled', false)
            ->assertJsonPath('venueName', $venue->name)
            ->assertJsonPath('venueCapacity', $venue->capacity)
            ->assertJsonCount($venue->zones->count(), 'zones');

        $id = $response->json('id');

        $this->assertDatabaseHas('events', [
            'id' => $id,
            'organizer_id' => $organizer->id,
            'status' => 'PENDING_APPROVAL',
            'published_at' => null,
        ]);

        foreach ($response->json('zones') as $zone) {
            $this->assertSame(
                $zone['capacity'],
                $zone['availableCapacity'],
            );
        }

        $this->assertDatabaseCount('event_banners', 1);

        $banner = EventBanner::where('event_id', $id)->firstOrFail();

        $this->assertNotEmpty($banner->content);
        $this->assertNotEmpty($banner->secondary_content);

        $this->get("/api/events/{$id}/banner")->assertNotFound();

        $this->get("/api/events/{$id}/secondary-banner")
            ->assertNotFound();

        $this->getJson('/api/events')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_unauthenticated_or_other_roles_cannot_create_events_or_list_venues(): void
    {
        $this->postJson('/api/events', $this->eventPayload())->assertUnauthorized();
        $this->getJson('/api/v1/recintos')->assertUnauthorized();
        foreach (['CLIENT', 'ADMIN'] as $role) {
            $user = $this->actor($role);
            $this->postJson('/api/events', $this->eventPayload(), $this->headersFor($user))->assertForbidden();
            $this->getJson('/api/v1/recintos', $this->headersFor($user))->assertForbidden();
        }
    }

    public function test_deactivated_organizer_cannot_publish_with_an_existing_token(): void
    {
        $organizer = $this->actor('ORGANIZER');
        $headers = $this->headersFor($organizer);
        $organizer->update(['active' => false]);
        $this->postJson('/api/events', $this->eventPayload(), $headers)->assertForbidden();
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('event_banners', 0);
    }

    public function test_required_fields_and_dates_are_validated(): void
    {
        $organizer = $this->actor('ORGANIZER');
        $this->postJson('/api/events', [], $this->headersFor($organizer))->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description', 'category', 'venueId', 'banner', 'secondaryBanner', 'startsAt', 'zones']);
        $this->postJson('/api/events', $this->eventPayload(['startsAt' => now()->subDay()->toIso8601String()]),
            $this->headersFor($organizer))->assertUnprocessable()->assertJsonValidationErrors('startsAt');
        $this->postJson('/api/events', $this->eventPayload(['endsAt' => now()->toIso8601String()]),
            $this->headersFor($organizer))->assertUnprocessable()->assertJsonValidationErrors('endsAt');
    }

    public static function invalidPrices(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'precision' => [1.001], 'overflow' => [100000000]];
    }

    #[DataProvider('invalidPrices')]
    public function test_zone_price_must_be_positive_money(int|float $price): void
    {
        $payload = $this->eventPayload();
        $payload['zones'][0]['basePrice'] = $price;
        $this->postJson('/api/events', $payload, $this->headersFor($this->actor('ORGANIZER')))
            ->assertUnprocessable()->assertJsonValidationErrors('zones.0.basePrice');
        $this->assertDatabaseCount('events', 0);
    }

    public function test_cannot_override_venue_data_or_zone_capacity(): void
    {
        $payload = $this->eventPayload(['venueName' => 'Manipulado', 'venueAddress' => 'Otra', 'venueCapacity' => 99999]);
        $payload['zones'][0]['capacity'] = 99999;
        $payload['zones'][0]['name'] = 'Manipulada';
        $this->postJson('/api/events', $payload, $this->headersFor($this->actor('ORGANIZER')))
            ->assertUnprocessable()->assertJsonValidationErrors(['venueName', 'venueAddress', 'venueCapacity',
                'zones.0.capacity', 'zones.0.name']);
    }

    public function test_zone_set_must_match_the_venue_exactly(): void
    {
        $organizer = $this->actor('ORGANIZER');
        $payload = $this->eventPayload();
        $missing = $payload;
        array_pop($missing['zones']);
        $this->postJson('/api/events', $missing, $this->headersFor($organizer))
            ->assertUnprocessable()->assertJsonValidationErrors('zones');
        $duplicate = $payload;
        $duplicate['zones'][1]['venueZoneId'] = $duplicate['zones'][0]['venueZoneId'];
        $this->postJson('/api/events', $duplicate, $this->headersFor($organizer))->assertUnprocessable();
        $foreign = $payload;
        $foreign['zones'][0]['venueZoneId'] = Venue::with('zones')->skip(1)->first()->zones->first()->id;
        $this->postJson('/api/events', $foreign, $this->headersFor($organizer))->assertUnprocessable();
        $this->assertDatabaseCount('events', 0);
    }

    public function test_banner_rejects_wrong_format_small_dimensions_and_oversize(): void
    {
        $organizer = $this->actor('ORGANIZER');
        $badFiles = [
            UploadedFile::fake()->createWithContent('banner.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j3ioAAAAASUVORK5CYII=')),
            $this->bannerFile('small-banner.jpg'),
            UploadedFile::fake()->createWithContent('large.jpg', file_get_contents(base_path('tests/Fixtures/banner.jpg')).str_repeat('x', 2 * 1024 * 1024)),
        ];
        foreach ($badFiles as $file) {
            $this->postJson('/api/events', $this->eventPayload(['banner' => $file]), $this->headersFor($organizer))
                ->assertUnprocessable()->assertJsonValidationErrors('banner');
        }
        $this->assertDatabaseCount('event_banners', 0);
    }

    public function test_same_venue_requires_twenty_four_hours_even_across_midnight(): void
    {
        $headers = $this->headersFor($this->actor('ORGANIZER'));

        $start = now()
            ->addMonth()
            ->setTimezone('America/Lima')
            ->startOfDay()
            ->addHours(23);

        $this->postJson(
            '/api/events',
            $this->eventPayload([
                'startsAt' => $start->toIso8601String(),
            ]),
            $headers,
        )->assertCreated();

        // Otro día calendario, pero solo dos horas después.
        $this->postJson(
            '/api/events',
            $this->eventPayload([
                'startsAt' => $start->copy()->addHours(2)->toIso8601String(),
            ]),
            $headers,
        )->assertConflict();

        // Exactamente 24 horas después sí se permite.
        $this->postJson(
            '/api/events',
            $this->eventPayload([
                'startsAt' => $start->copy()->addHours(24)->toIso8601String(),
            ]),
            $headers,
        )->assertCreated();

        $this->assertDatabaseCount('events', 2);
        $this->assertDatabaseCount('event_banners', 2);
    }

    public function test_another_venue_is_available_on_the_same_day(): void
    {
        $this->publishedEvent();
        $venue = Venue::with('zones')->skip(1)->firstOrFail();
        $this->postJson('/api/events', $this->eventPayload([
            'venueId' => $venue->id,
            'zones' => $venue->zones->map(fn ($zone) => ['venueZoneId' => $zone->id, 'basePrice' => 50])->all(),
        ]), $this->headersFor($this->actor('ORGANIZER')))->assertCreated();
    }

    public function test_mine_lists_only_own_events_and_returns_empty_list_for_new_organizer(): void
    {
        $owner = $this->actor('ORGANIZER');
        $event = $this->publishedEvent(['organizer_id' => $owner->id]);
        $this->publishedEvent();
        $this->getJson('/api/v1/eventos/mios', $this->headersFor($owner))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', (string) $event->id);
        $this->getJson('/api/v1/eventos/mios', $this->headersFor($this->actor('ORGANIZER')))
            ->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/eventos/mios', $this->headersFor($this->actor()))->assertForbidden();
    }

    public function test_unpublished_event_and_its_banner_are_not_publicly_accessible(): void
    {
        $event = $this->publishedEvent(['status' => 'DRAFT']);
        $event->banner()->create(['content' => base64_encode(file_get_contents(base_path('tests/Fixtures/banner.jpg')))]);
        $this->getJson('/api/events/'.$event->id)->assertNotFound()->assertExactJson(['message' => 'Evento no disponible']);
        $this->get('/api/events/'.$event->id.'/banner')->assertNotFound();
        $this->getJson('/api/events/999999')->assertNotFound();
    }

    public function test_catalog_date_filter_uses_the_same_lima_calendar_as_venue_bookings(): void
    {
        $lateNight = $this->publishedEvent(['starts_at' => '2026-11-25T04:30:00Z']);
        $this->publishedEvent(['starts_at' => '2026-11-25T05:30:00Z']);
        $this->getJson('/api/events?date=2026-11-24')->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.id', (string) $lateNight->id);
    }

    public function test_event_editing_is_explicitly_forbidden(): void
    {
        $event = $this->publishedEvent();
        $owner = $event->organizer;
        foreach (['PUT', 'PATCH'] as $method) {
            $this->json($method, '/api/events/'.$event->id, ['title' => 'Cambiar'], $this->headersFor($owner))->assertForbidden();
        }
        $this->assertSame('Evento Demo 3', $event->fresh()->title);
    }

    public function test_banner_failure_rolls_back_event_and_zones(): void
    {
        $dispatcher = EventBanner::getEventDispatcher();
        EventBanner::setEventDispatcher(clone $dispatcher);
        EventBanner::creating(fn () => throw new \RuntimeException('Simulated banner failure'));
        try {
            $this->withoutExceptionHandling();
            $this->postJson('/api/events', $this->eventPayload(), $this->headersFor($this->actor('ORGANIZER')));
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated banner failure', $exception->getMessage());
            $this->assertDatabaseCount('events', 0);
            $this->assertDatabaseCount('event_zones', 0);
        } finally {
            EventBanner::setEventDispatcher($dispatcher);
        }
    }
}

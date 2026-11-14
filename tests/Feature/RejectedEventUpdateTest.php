<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventBanner;
use App\Models\Ticket;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class RejectedEventUpdateTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDemo();
    }

    private function rejectedEvent(): Event
    {
        $event = $this->publishedEvent([
            'status' => Event::STATUS_REJECTED,
            'rejection_reason' => 'Corregir información.',
            'reviewed_at' => now(),
            'reviewed_by' => $this->actor('ADMIN')->id,
        ]);

        $bytes = file_get_contents(base_path('tests/Fixtures/banner.jpg'));

        $event->banner()->create([
            'content' => base64_encode($bytes),
            'secondary_content' => base64_encode($bytes),
        ]);

        return $event;
    }

    private function snapshot(Event $event): array
    {
        return [
            'event' => (array) DB::table('events')
                ->where('id', $event->id)
                ->first(),
            'zones' => DB::table('event_zones')
                ->where('event_id', $event->id)
                ->orderBy('id')
                ->get()
                ->map(fn ($zone) => (array) $zone)
                ->all(),
            'banner' => (array) DB::table('event_banners')
                ->where('event_id', $event->id)
                ->first(),
        ];
    }

    public function test_owner_can_resubmit_and_admin_can_approve(): void
    {
        $event = $this->rejectedEvent();
        $owner = $event->organizer;

        $payload = $this->eventPayload([
            'title' => 'Evento corregido',
            'queueEnabled' => true,
        ]);

        $this->putJson(
            "/api/v1/eventos/{$event->id}",
            $payload,
            $this->headersFor($owner),
        )->assertOk()
            ->assertJsonPath('id', (string) $event->id)
            ->assertJsonPath('status', Event::STATUS_PENDING_APPROVAL)
            ->assertJsonPath('title', 'Evento corregido')
            ->assertJsonPath('queueEnabled', true)
            ->assertJsonPath('canEdit', false)
            ->assertJsonPath('rejectionReason', null);

        $saved = $event->fresh();

        $this->assertSame($owner->id, $saved->organizer_id);
        $this->assertNull($saved->reviewed_at);
        $this->assertNull($saved->reviewed_by);
        $this->assertNull($saved->published_at);
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('event_banners', 1);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertExactJson([]);

        $adminHeaders = $this->headersFor($this->actor('ADMIN'));

        $this->getJson(
            '/api/v1/admin/eventos?estado=pendiente',
            $adminHeaders,
        )->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', (string) $event->id);

        $this->patchJson(
            "/api/v1/admin/eventos/{$event->id}/estado",
            ['status' => Event::STATUS_PUBLISHED],
            $adminHeaders,
        )->assertOk();

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', (string) $event->id)
            ->assertJsonPath('0.name', 'Evento corregido')
            ->assertJsonPath('0.status', 'PUBLICADO');
    }

    #[DataProvider('frontendUpdateStatuses')]
    public function test_json_edit_cannot_publish_or_change_owner(string $status): void
    {
        $event = $this->rejectedEvent();
        $venue = Venue::with('zones')->findOrFail($event->venue_id);
        $otherOwner = $this->actor('ORGANIZER');
        $bytes = file_get_contents(base_path('tests/Fixtures/banner.jpg'));

        $payload = [
            'id' => (string) $event->id,
            'name' => 'Evento JSON corregido',
            'description' => 'Descripción corregida y completa del evento.',
            'category' => 'CONCIERTO',
            'status' => $status,
            'venue' => $venue->name,
            'city' => $venue->city,
            'venueCapacity' => $venue->capacity,
            'startsAt' => now()->addMonths(2)->toIso8601String(),
            'publishedAt' => null,
            'organizerId' => (string) $otherOwner->id,
            'maxPerOrder' => 6,
            'queueEnabled' => true,
            'bannerUrl' => 'data:image/jpeg;base64,'.base64_encode($bytes),
            'secondaryBannerUrl' => 'data:image/jpeg;base64,'
                .base64_encode($bytes."\nsecondary"),
            'zones' => $venue->zones->map(
                fn ($zone, $index) => [
                    'id' => 'temporary-'.$index,
                    'name' => $zone->name,
                    'capacity' => $zone->capacity,
                    'sold' => 0,
                    'price' => 75,
                ]
            )->all(),
        ];

        $this->putJson(
            "/api/events/{$event->id}",
            $payload,
            $this->headersFor($event->organizer),
        )->assertOk()
            ->assertJsonPath('status', 'PENDIENTE_APROBACION')
            ->assertJsonPath('backendStatus', 'PENDING_APPROVAL')
            ->assertJsonPath('organizerId', (string) $event->organizer_id)
            ->assertJsonPath('publishedAt', null);

        $this->assertSame(
            Event::STATUS_PENDING_APPROVAL,
            $event->fresh()->status,
        );

        $this->assertSame(
            $event->organizer_id,
            $event->fresh()->organizer_id,
        );
    }

    public static function frontendUpdateStatuses(): array
    {
        return [['PUBLICADO'], ['PENDIENTE']];
    }

    public static function forbiddenStates(): array
    {
        return [
            'pending' => ['PENDING_APPROVAL'],
            'published' => ['PUBLISHED'],
            'draft' => ['DRAFT'],
        ];
    }

    #[DataProvider('forbiddenStates')]
    public function test_only_rejected_events_can_be_edited(
        string $status,
    ): void {
        $event = $this->rejectedEvent();
        $event->update(['status' => $status]);
        $before = $this->snapshot($event);

        // También comprueba que el permiso se evalúa antes del payload.
        $this->putJson(
            "/api/v1/eventos/{$event->id}",
            [],
            $this->headersFor($event->organizer),
        )->assertForbidden();

        $this->assertSame($before, $this->snapshot($event));
    }

    public function test_anonymous_request_is_rejected(): void
    {
        $event = $this->rejectedEvent();
        $before = $this->snapshot($event);

        $this->putJson(
            "/api/v1/eventos/{$event->id}",
            [],
        )->assertUnauthorized();

        $this->assertSame($before, $this->snapshot($event));
    }

    public static function forbiddenActors(): array
    {
        return [
            'another organizer' => ['ORGANIZER'],
            'client' => ['CLIENT'],
            'admin' => ['ADMIN'],
        ];
    }

    #[DataProvider('forbiddenActors')]
    public function test_other_actors_cannot_edit(string $role): void
    {
        $event = $this->rejectedEvent();
        $before = $this->snapshot($event);

        $this->putJson(
            "/api/v1/eventos/{$event->id}",
            [],
            $this->headersFor($this->actor($role)),
        )->assertForbidden();

        $this->assertSame($before, $this->snapshot($event));
    }

    public function test_inactive_owner_cannot_edit_with_existing_token(): void
    {
        $event = $this->rejectedEvent();
        $owner = $event->organizer;
        $headers = $this->headersFor($owner);
        $owner->update(['active' => false]);
        $before = $this->snapshot($event);

        $this->putJson(
            "/api/v1/eventos/{$event->id}",
            [],
            $headers,
        )->assertForbidden();

        $this->assertSame($before, $this->snapshot($event));
    }

    public static function invalidEdits(): array
    {
        return [
            'past date' => [
                'startsAt', '2026-10-24T00:00:00Z', 'startsAt',
            ],
            'invalid price' => [
                'zones.0.basePrice', 0, 'zones.0.basePrice',
            ],
            'missing secondary banner' => [
                'secondaryBanner', null, 'secondaryBanner',
            ],
            'invalid queue option' => [
                'queueEnabled', 'invalid', 'queueEnabled',
            ],
        ];
    }

    #[DataProvider('invalidEdits')]
    public function test_invalid_edit_preserves_all_previous_data(
        string $field,
        mixed $value,
        string $error,
    ): void {
        $event = $this->rejectedEvent();
        $before = $this->snapshot($event);
        $payload = $this->eventPayload();

        data_set($payload, $field, $value);

        $this->putJson(
            "/api/v1/eventos/{$event->id}",
            $payload,
            $this->headersFor($event->organizer),
        )->assertUnprocessable()
            ->assertJsonValidationErrors($error);

        $this->assertSame($before, $this->snapshot($event));
    }

    public function test_conflicting_resubmission_preserves_previous_data(): void
    {
        $event = $this->rejectedEvent();

        $this->publishedEvent([
            'venue_id' => $event->venue_id,
            'starts_at' => $event->starts_at,
        ]);

        $before = $this->snapshot($event);

        $this->putJson(
            "/api/v1/eventos/{$event->id}",
            $this->eventPayload(),
            $this->headersFor($event->organizer),
        )->assertConflict();

        $this->assertSame($before, $this->snapshot($event));
    }

    public function test_banner_write_failure_rolls_back_the_entire_edit(): void
    {
        $event = $this->rejectedEvent();
        $before = $this->snapshot($event);
        $headers = $this->headersFor($event->organizer);

        $bytes = file_get_contents(base_path('tests/Fixtures/banner.jpg'));

        $payload = $this->eventPayload([
            'title' => 'Cambio que debe revertirse',
            'secondaryBanner' => UploadedFile::fake()->createWithContent(
                'updated-secondary.jpg',
                $bytes."\nupdated-secondary",
            ),
        ]);

        $dispatcher = EventBanner::getEventDispatcher();
        EventBanner::setEventDispatcher(clone $dispatcher);

        EventBanner::updating(
            fn () => throw new \RuntimeException('Simulated update failure')
        );

        $caught = null;

        try {
            $this->withoutExceptionHandling();

            $this->putJson(
                "/api/v1/eventos/{$event->id}",
                $payload,
                $headers,
            );
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        } finally {
            EventBanner::setEventDispatcher($dispatcher);
        }

        $this->assertNotNull(
            $caught,
            'La actualización del banner debía provocar el fallo simulado.',
        );

        $this->assertSame(
            'Simulated update failure',
            $caught->getMessage(),
        );

        $this->assertSame($before, $this->snapshot($event));
    }

    public function test_unknown_event_returns_404(): void
    {
        $this->putJson(
            '/api/v1/eventos/999999',
            [],
            $this->headersFor($this->actor('ORGANIZER')),
        )->assertNotFound();
    }

    public function test_owner_can_change_venue_when_resubmitting(): void
    {
        $event = $this->rejectedEvent();
        $originalOwnerId = $event->organizer_id;

        $venue = Venue::with('zones')
            ->where('id', '!=', $event->venue_id)
            ->whereNotNull('slug')
            ->firstOrFail();

        $payload = $this->eventPayload([
            'venueId' => $venue->id,
            'zones' => $venue->zones->map(fn ($zone) => [
                'venueZoneId' => $zone->id,
                'basePrice' => 85,
            ])->all(),
        ]);

        $this->putJson(
            "/api/v1/eventos/{$event->id}",
            $payload,
            $this->headersFor($event->organizer),
        )->assertOk()
            ->assertJsonPath('id', (string) $event->id)
            ->assertJsonPath('venueId', (string) $venue->id)
            ->assertJsonPath('venueName', $venue->name)
            ->assertJsonPath('venueCapacity', $venue->capacity)
            ->assertJsonPath('status', Event::STATUS_PENDING_APPROVAL);

        $saved = $event->fresh()->load('zones');

        $this->assertSame($originalOwnerId, $saved->organizer_id);
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('event_banners', 1);
        $this->assertCount($venue->zones->count(), $saved->zones);

        $this->assertEqualsCanonicalizing(
            $venue->zones->pluck('id')->all(),
            $saved->zones->pluck('venue_zone_id')->all(),
        );

        foreach ($saved->zones as $zone) {
            $template = $venue->zones->firstWhere(
                'id',
                $zone->venue_zone_id,
            );

            $this->assertSame($template->name, $zone->name);
            $this->assertSame($template->capacity, $zone->capacity);
            $this->assertSame($zone->capacity, $zone->available_capacity);
            $this->assertSame('85.00', (string) $zone->base_price);
        }
    }

    public function test_occupied_new_venue_does_not_change_original_event(): void
    {
        $event = $this->rejectedEvent();

        $venue = Venue::with('zones')
            ->where('id', '!=', $event->venue_id)
            ->whereNotNull('slug')
            ->firstOrFail();

        $this->publishedEvent([
            'venue_id' => $venue->id,
            'venue_name' => $venue->name,
            'venue_address' => $venue->address,
            'venue_capacity' => $venue->capacity,
            'starts_at' => $event->starts_at,
        ]);

        $before = $this->snapshot($event);

        $payload = $this->eventPayload([
            'venueId' => $venue->id,
            'zones' => $venue->zones->map(fn ($zone) => [
                'venueZoneId' => $zone->id,
                'basePrice' => 85,
            ])->all(),
        ]);

        $this->putJson(
            "/api/v1/eventos/{$event->id}",
            $payload,
            $this->headersFor($event->organizer),
        )->assertConflict();

        $this->assertSame($before, $this->snapshot($event));
    }

    public function test_rejected_date_can_be_reused_but_blocks_its_resubmission(): void
    {
        $event = $this->rejectedEvent();
        $before = $this->snapshot($event);
        $headers = $this->headersFor($event->organizer);

        // El rechazado no reserva el recinto.
        $response = $this->postJson(
            '/api/events',
            $this->eventPayload(['title' => 'Nueva solicitud']),
            $headers,
        )->assertCreated()
            ->assertJsonPath('status', Event::STATUS_PENDING_APPROVAL);

        $this->assertNotSame(
            (string) $event->id,
            $response->json('id'),
        );

        // La nueva solicitud pendiente ahora sí ocupa esa fecha.
        $this->putJson(
            "/api/v1/eventos/{$event->id}",
            $this->eventPayload(),
            $headers,
        )->assertConflict();

        $this->assertSame($before, $this->snapshot($event));
        $this->assertDatabaseCount('events', 2);
    }

    public function test_rejected_event_with_ticket_history_cannot_be_rewritten(): void
    {
        $event = $this->rejectedEvent();
        $zone = $event->zones()->firstOrFail();

        $ticket = Ticket::create([
            'user_id' => $this->actor('CLIENT')->id,
            'event_zone_id' => $zone->id,
            'quantity' => 1,
            'total_price' => 50,
            'code' => 'HISTORICAL-TICKET-UPDATE-TEST',
        ]);

        $before = $this->snapshot($event);

        $this->putJson(
            "/api/v1/eventos/{$event->id}",
            $this->eventPayload(),
            $this->headersFor($event->organizer),
        )->assertConflict();

        $this->assertSame($before, $this->snapshot($event));

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'event_zone_id' => $zone->id,
            'code' => 'HISTORICAL-TICKET-UPDATE-TEST',
            'total_price' => 50,
        ]);
    }
}

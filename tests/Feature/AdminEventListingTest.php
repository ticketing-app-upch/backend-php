<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class AdminEventListingTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    private const URL = '/api/v1/admin/eventos';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDemo();
    }

    private function pendingEvent(array $overrides = []): Event
    {
        return $this->publishedEvent(array_replace([
            'status' => Event::STATUS_PENDING_APPROVAL,
        ], $overrides));
    }

    public function test_list_contains_only_pending_future_events(): void
    {
        $later = $this->pendingEvent([
            'starts_at' => now()->addDays(10),
        ]);
        $earlier = $this->pendingEvent([
            'starts_at' => now()->addDays(5),
            'queue_enabled' => true,
        ]);

        $this->publishedEvent();
        $this->publishedEvent(['status' => Event::STATUS_REJECTED]);
        $this->publishedEvent(['status' => Event::STATUS_DRAFT]);
        $this->pendingEvent(['starts_at' => now()]);
        $this->pendingEvent(['starts_at' => now()->subSecond()]);

        $response = $this->getJson(
            self::URL.'?estado=pendiente',
            $this->headersFor($this->actor('ADMIN')),
        )->assertOk()->assertJsonCount(2);

        $this->assertSame(
            [(string) $earlier->id, (string) $later->id],
            array_column($response->json(), 'id'),
        );

        $response->assertJsonPath('0.queueEnabled', true)
            ->assertJsonPath(
                '0.organizer.id',
                (string) $earlier->organizer_id,
            );
    }

    public function test_no_pending_events_returns_empty_array(): void
    {
        $this->publishedEvent();

        $this->getJson(
            self::URL,
            $this->headersFor($this->actor('ADMIN')),
        )->assertOk()->assertExactJson([]);
    }

    public function test_detail_exposes_venue_zones_and_private_image_urls(): void
    {
        $event = $this->pendingEvent();
        $bytes = file_get_contents(base_path('tests/Fixtures/banner.jpg'));

        $event->banner()->create([
            'content' => base64_encode($bytes),
            'secondary_content' => base64_encode($bytes),
        ]);

        $headers = $this->headersFor($this->actor('ADMIN'));

        $response = $this->getJson(
            self::URL."/{$event->id}",
            $headers,
        )->assertOk()
            ->assertJsonPath('id', (string) $event->id)
            ->assertJsonPath('status', Event::STATUS_PENDING_APPROVAL)
            ->assertJsonCount($event->zones()->count(), 'zones');

        $this->assertNotEmpty($response->json('venue'));

        $this->assertSame(
            rtrim(config('app.url'), '/')
                .self::URL."/{$event->id}/banner",
            $response->json('bannerUrl'),
        );

        $this->assertStringNotContainsString(
            base64_encode($bytes),
            $response->getContent(),
        );

        foreach (['banner', 'secondary-banner'] as $image) {
            $this->get(
                self::URL."/{$event->id}/{$image}",
                $headers,
            )->assertOk()
                ->assertHeader('Content-Type', 'image/jpeg')
                ->assertContent($bytes);
        }

        // Las rutas públicas siguen sin exponer un evento pendiente.
        $this->get("/api/events/{$event->id}/banner")->assertNotFound();
        $this->get("/api/events/{$event->id}/secondary-banner")
            ->assertNotFound();
    }

    public static function restrictedActors(): array
    {
        return [
            'anonymous' => [null, 401],
            'client' => ['CLIENT', 403],
            'organizer' => ['ORGANIZER', 403],
        ];
    }

    #[DataProvider('restrictedActors')]
    public function test_listing_detail_and_images_require_admin(
        ?string $role,
        int $expectedStatus,
    ): void {
        $event = $this->pendingEvent();
        $headers = $role === null
            ? []
            : $this->headersFor($this->actor($role));

        foreach ([
            self::URL,
            self::URL."/{$event->id}",
            self::URL."/{$event->id}/banner",
            self::URL."/{$event->id}/secondary-banner",
        ] as $url) {
            $this->getJson($url, $headers)
                ->assertStatus($expectedStatus);
        }
    }

    public function test_non_pending_expired_and_unknown_details_are_unavailable(): void
    {
        $published = $this->publishedEvent();
        $rejected = $this->publishedEvent([
            'status' => Event::STATUS_REJECTED,
        ]);
        $expired = $this->pendingEvent(['starts_at' => now()]);

        $headers = $this->headersFor($this->actor('ADMIN'));

        foreach ([
            $published->id,
            $rejected->id,
            $expired->id,
            999999,
        ] as $id) {
            foreach (['', '/banner', '/secondary-banner'] as $suffix) {
                $this->getJson(self::URL."/{$id}{$suffix}", $headers)
                    ->assertNotFound();
            }
        }
    }

    public function test_missing_banner_returns_404(): void
    {
        $event = $this->pendingEvent();
        $headers = $this->headersFor($this->actor('ADMIN'));

        foreach (['banner', 'secondary-banner'] as $image) {
            $this->get(self::URL."/{$event->id}/{$image}", $headers)
                ->assertNotFound();
        }
    }

    public function test_invalid_state_filter_returns_422(): void
    {
        $this->getJson(
            self::URL.'?estado=publicado',
            $this->headersFor($this->actor('ADMIN')),
        )->assertUnprocessable()->assertJsonValidationErrors('estado');
    }
}

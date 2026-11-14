<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class OrganizerEventAccessTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDemo();
    }

    public static function privateStates(): array
    {
        return [
            'pending' => ['PENDING_APPROVAL'],
            'rejected' => ['REJECTED'],
        ];
    }

    #[DataProvider('privateStates')]
    public function test_owner_can_view_private_detail_and_banners(
        string $status,
    ): void {
        $event = $this->publishedEvent([
            'status' => $status,
            'rejection_reason' => $status === Event::STATUS_REJECTED
                ? 'Corregir información.'
                : null,
        ]);

        $bytes = file_get_contents(base_path('tests/Fixtures/banner.jpg'));

        $event->banner()->create([
            'content' => base64_encode($bytes),
            'secondary_content' => base64_encode($bytes),
        ]);

        $headers = $this->headersFor($event->organizer);
        $path = "/api/v1/eventos/{$event->id}";
        $baseUrl = rtrim(config('app.url'), '/').$path;

        $this->getJson($path, $headers)
            ->assertOk()
            ->assertJsonPath('id', (string) $event->id)
            ->assertJsonPath('status', $status)
            ->assertJsonPath('bannerUrl', $baseUrl.'/banner')
            ->assertJsonPath(
                'secondaryBannerUrl',
                $baseUrl.'/secondary-banner',
            );

        foreach (['banner', 'secondary-banner'] as $image) {
            $response = $this->get($path.'/'.$image, $headers)
                ->assertOk()
                ->assertHeader('Content-Type', 'image/jpeg')
                ->assertContent($bytes);

            $cache = $response->headers->get('Cache-Control');

            $this->assertStringContainsString('private', $cache);
            $this->assertStringContainsString('no-store', $cache);

            $this->get("/api/events/{$event->id}/{$image}")
                ->assertNotFound();
        }

        $this->getJson("/api/events/{$event->id}")
            ->assertNotFound();

        $this->getJson("/api/events/{$event->id}", $this->headersFor($event->organizer))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('name', $event->title)
            ->assertJsonPath('bannerUrl', 'data:image/jpeg;base64,'.base64_encode($bytes));

        $this->getJson("/api/events/{$event->id}", $this->headersFor($this->actor('ORGANIZER')))
            ->assertNotFound();
    }

    public static function restrictedActors(): array
    {
        return [
            'anonymous' => [null, 401],
            'another organizer' => ['ORGANIZER', 404],
            'client' => ['CLIENT', 403],
            'admin uses a separate endpoint' => ['ADMIN', 403],
        ];
    }

    #[DataProvider('restrictedActors')]
    public function test_other_actors_cannot_access_private_event(
        ?string $role,
        int $expectedStatus,
    ): void {
        $event = $this->publishedEvent([
            'status' => Event::STATUS_REJECTED,
        ]);

        $headers = $role === null
            ? []
            : $this->headersFor($this->actor($role));

        foreach (['', '/banner', '/secondary-banner'] as $suffix) {
            $this->getJson(
                "/api/v1/eventos/{$event->id}{$suffix}",
                $headers,
            )->assertStatus($expectedStatus);
        }
    }

    public function test_unknown_event_and_missing_banners_return_404(): void
    {
        $event = $this->publishedEvent([
            'status' => Event::STATUS_PENDING_APPROVAL,
        ]);
        $headers = $this->headersFor($event->organizer);

        $this->getJson('/api/v1/eventos/999999', $headers)
            ->assertNotFound();

        foreach (['banner', 'secondary-banner'] as $image) {
            $this->get(
                "/api/v1/eventos/{$event->id}/{$image}",
                $headers,
            )->assertNotFound();
        }
    }
}

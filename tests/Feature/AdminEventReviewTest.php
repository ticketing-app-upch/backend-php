<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class AdminEventReviewTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDemo();
    }

    private function reviewUrl(Event $event): string
    {
        return "/api/v1/admin/eventos/{$event->id}/estado";
    }

    public function test_review_requires_authentication(): void
    {
        $event = $this->publishedEvent([
            'status' => Event::STATUS_PENDING_APPROVAL,
        ]);

        $this->patchJson($this->reviewUrl($event), [
            'status' => Event::STATUS_PUBLISHED,
        ])->assertUnauthorized();

        $this->assertSame(
            Event::STATUS_PENDING_APPROVAL,
            $event->fresh()->status,
        );
    }

    public static function nonAdminRoles(): array
    {
        return [
            'client' => ['CLIENT'],
            'organizer' => ['ORGANIZER'],
        ];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_other_roles_cannot_review(string $role): void
    {
        $actor = $this->actor($role);
        $event = $this->publishedEvent([
            'organizer_id' => $actor->id,
            'status' => Event::STATUS_PENDING_APPROVAL,
        ]);

        $this->patchJson(
            $this->reviewUrl($event),
            ['status' => Event::STATUS_PUBLISHED],
            $this->headersFor($actor),
        )->assertForbidden();

        $this->assertSame(
            Event::STATUS_PENDING_APPROVAL,
            $event->fresh()->status,
        );
    }

    public function test_admin_can_approve_and_public_catalog_includes_event(): void
    {
        $admin = $this->actor('ADMIN');
        $event = $this->publishedEvent([
            'status' => Event::STATUS_PENDING_APPROVAL,
        ]);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonMissing(['id' => (string) $event->id]);

        $this->patchJson(
            $this->reviewUrl($event),
            [
                'status' => Event::STATUS_PUBLISHED,
                'reviewedBy' => '999999',
            ],
            $this->headersFor($admin),
        )->assertOk()
            ->assertJsonPath('status', Event::STATUS_PUBLISHED)
            ->assertJsonPath('reviewedBy', (string) $admin->id)
            ->assertJsonPath('rejectionReason', null);

        $saved = $event->fresh();

        $this->assertSame($admin->id, $saved->reviewed_by);
        $this->assertNotNull($saved->reviewed_at);
        $this->assertNotNull($saved->published_at);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonFragment(['id' => (string) $event->id]);
    }

    public function test_admin_can_reject_with_a_reason(): void
    {
        $event = $this->publishedEvent([
            'status' => Event::STATUS_PENDING_APPROVAL,
        ]);

        $this->patchJson(
            $this->reviewUrl($event),
            [
                'status' => Event::STATUS_REJECTED,
                'rejectionReason' => 'Corregir la descripción del evento.',
            ],
            $this->headersFor($this->actor('ADMIN')),
        )->assertOk()
            ->assertJsonPath('status', Event::STATUS_REJECTED)
            ->assertJsonPath(
                'rejectionReason',
                'Corregir la descripción del evento.',
            )
            ->assertJsonPath('publishedAt', null);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonMissing(['id' => (string) $event->id]);

        $this->getJson("/api/events/{$event->id}")
            ->assertNotFound();
    }

    public static function invalidReviews(): array
    {
        return [
            'missing decision' => [[], 'status'],
            'invalid decision' => [
                ['status' => 'DRAFT'],
                'status',
            ],
            'missing rejection reason' => [
                ['status' => 'REJECTED'],
                'rejectionReason',
            ],
            'blank rejection reason' => [
                ['status' => 'REJECTED', 'rejectionReason' => '   '],
                'rejectionReason',
            ],
            'reason on approval' => [
                ['status' => 'PUBLISHED', 'rejectionReason' => 'Motivo'],
                'rejectionReason',
            ],
            'oversized reason' => [
                [
                    'status' => 'REJECTED',
                    'rejectionReason' => str_repeat('a', 2001),
                ],
                'rejectionReason',
            ],
        ];
    }

    #[DataProvider('invalidReviews')]
    public function test_invalid_review_does_not_change_event(
        array $payload,
        string $field,
    ): void {
        $event = $this->publishedEvent([
            'status' => Event::STATUS_PENDING_APPROVAL,
        ]);

        $this->patchJson(
            $this->reviewUrl($event),
            $payload,
            $this->headersFor($this->actor('ADMIN')),
        )->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $saved = $event->fresh();

        $this->assertSame(Event::STATUS_PENDING_APPROVAL, $saved->status);
        $this->assertNull($saved->reviewed_at);
        $this->assertNull($saved->reviewed_by);
    }

    public function test_already_reviewed_event_cannot_be_reviewed_again(): void
    {
        $event = $this->publishedEvent([
            'status' => Event::STATUS_PENDING_APPROVAL,
        ]);
        $headers = $this->headersFor($this->actor('ADMIN'));
        $payload = ['status' => Event::STATUS_PUBLISHED];

        $this->patchJson($this->reviewUrl($event), $payload, $headers)
            ->assertOk();

        $this->patchJson($this->reviewUrl($event), [
            'status' => Event::STATUS_REJECTED,
            'rejectionReason' => 'Otro intento de revisión.',
        ], $headers)->assertConflict();

        $this->assertSame(
            Event::STATUS_PUBLISHED,
            $event->fresh()->status,
        );
    }

    public function test_event_at_its_start_time_cannot_be_approved(): void
    {
        $event = $this->publishedEvent([
            'status' => Event::STATUS_PENDING_APPROVAL,
            'starts_at' => now(),
        ]);

        $this->patchJson(
            $this->reviewUrl($event),
            ['status' => Event::STATUS_PUBLISHED],
            $this->headersFor($this->actor('ADMIN')),
        )->assertConflict()
            ->assertJsonPath('message', 'El evento ya venció');

        $this->assertSame(
            Event::STATUS_PENDING_APPROVAL,
            $event->fresh()->status,
        );
    }

    public function test_unknown_event_returns_404(): void
    {
        $this->patchJson(
            '/api/v1/admin/eventos/999999/estado',
            ['status' => Event::STATUS_PUBLISHED],
            $this->headersFor($this->actor('ADMIN')),
        )->assertNotFound();
    }
}

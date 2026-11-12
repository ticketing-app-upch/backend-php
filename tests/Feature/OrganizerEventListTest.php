<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class OrganizerEventListTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDemo();
    }

    public static function eventStates(): array
    {
        return [
            'pending' => [
                'PENDING_APPROVAL', 60, true,
                'PENDIENTE_APROBACION', false,
            ],
            'pending expired' => [
                'PENDING_APPROVAL', 0, true,
                'VENCIDO', false,
            ],
            'rejected' => [
                'REJECTED', 60, true,
                'RECHAZADO', true,
            ],
            'rejected inactive owner' => [
                'REJECTED', 60, false,
                'RECHAZADO', false,
            ],
            'published' => [
                'PUBLISHED', 60, true,
                'PUBLICADO', false,
            ],
            'finished' => [
                'PUBLISHED', -60, true,
                'FINALIZADO', false,
            ],
        ];
    }

    #[DataProvider('eventStates')]
    public function test_owner_sees_review_state_and_edit_permission(
        string $status,
        int $minutes,
        bool $active,
        string $displayStatus,
        bool $canEdit,
    ): void {
        $owner = $this->actor('ORGANIZER', $active);
        $reason = $status === Event::STATUS_REJECTED
            ? 'Corregir información del evento.'
            : null;

        $event = $this->publishedEvent([
            'organizer_id' => $owner->id,
            'status' => $status,
            'starts_at' => now()->addMinutes($minutes),
            'rejection_reason' => $reason,
        ]);

        // Un evento ajeno nunca debe entrar al listado.
        $this->publishedEvent([
            'rejection_reason' => 'Información de otro organizador.',
        ]);

        $this->getJson(
            '/api/v1/eventos/mios',
            $this->headersFor($owner),
        )->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', (string) $event->id)
            ->assertJsonPath('0.status', $status)
            ->assertJsonPath('0.displayStatus', $displayStatus)
            ->assertJsonPath('0.rejectionReason', $reason)
            ->assertJsonPath('0.canEdit', $canEdit);
    }

    public function test_public_catalog_does_not_expose_review_details(): void
    {
        $this->publishedEvent([
            'rejection_reason' => 'Información interna.',
            'reviewed_at' => now(),
        ]);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonMissingPath('0.rejectionReason')
            ->assertJsonMissingPath('0.reviewedAt')
            ->assertJsonMissingPath('0.canEdit');
    }

    public static function statesWithoutSalesReport(): array
    {
        return [
            'pending' => ['PENDING_APPROVAL'],
            'rejected' => ['REJECTED'],
            'draft' => ['DRAFT'],
        ];
    }

    #[DataProvider('statesWithoutSalesReport')]
    public function test_sales_report_requires_a_published_event(
        string $status,
    ): void {
        $event = $this->publishedEvent([
            'status' => $status,
        ]);

        $this->getJson(
            "/api/events/{$event->id}/sales-report",
            $this->headersFor($event->organizer),
        )->assertForbidden()
            ->assertJsonPath(
                'message',
                'El reporte solo está disponible para eventos publicados.',
            );
    }
}

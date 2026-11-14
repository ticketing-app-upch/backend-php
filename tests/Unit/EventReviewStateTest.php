<?php

namespace Tests\Unit;

use App\Models\Event;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventReviewStateTest extends TestCase
{
    public static function reviewExpirationCases(): array
    {
        return [
            'pending before its start' => [
                Event::STATUS_PENDING_APPROVAL,
                '2026-11-11T15:00:01Z',
                false,
            ],
            'pending exactly at its start' => [
                Event::STATUS_PENDING_APPROVAL,
                '2026-11-11T15:00:00Z',
                true,
            ],
            'pending after its start' => [
                Event::STATUS_PENDING_APPROVAL,
                '2026-11-11T14:59:59Z',
                true,
            ],
            'published past event is not an expired review' => [
                Event::STATUS_PUBLISHED,
                '2026-11-10T15:00:00Z',
                false,
            ],
            'rejected past event is not an expired review' => [
                Event::STATUS_REJECTED,
                '2026-11-10T15:00:00Z',
                false,
            ],
            'draft past event is not an expired review' => [
                Event::STATUS_DRAFT,
                '2026-11-10T15:00:00Z',
                false,
            ],
            'same instant expressed in Lima time' => [
                Event::STATUS_PENDING_APPROVAL,
                '2026-11-11T10:00:00-05:00',
                true,
            ],
        ];
    }

    #[DataProvider('reviewExpirationCases')]
    public function test_review_expiration_respects_state_and_time_boundary(
        string $status,
        string $startsAt,
        bool $expected,
    ): void {
        $this->travelTo(
            CarbonImmutable::parse('2026-11-11T15:00:00Z')
        );

        try {
            $event = new Event([
                'status' => $status,
                'starts_at' => $startsAt,
            ]);

            $this->assertSame($expected, $event->isReviewExpired());
            $this->assertSame($status, $event->status);
            $this->assertFalse($event->exists);
        } finally {
            $this->travelBack();
        }
    }
}

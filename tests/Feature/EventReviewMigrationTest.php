<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class EventReviewMigrationTest extends TestCase
{
    use BuildsDemoThreeFixtures, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDemo();
    }

    public function test_review_fields_have_safe_defaults(): void
    {
        $this->assertTrue(Schema::hasColumns('events', [
            'queue_enabled',
            'rejection_reason',
            'reviewed_at',
            'reviewed_by',
        ]));

        $event = $this->publishedEvent();
        $row = DB::table('events')->where('id', $event->id)->first();

        $this->assertSame('PUBLISHED', $row->status);
        $this->assertFalse((bool) $row->queue_enabled);
        $this->assertNull($row->rejection_reason);
        $this->assertNull($row->reviewed_at);
        $this->assertNull($row->reviewed_by);
    }

    public function test_migration_preserves_existing_events_and_zones(): void
    {
        $migration = require database_path(
            'migrations/2026_11_11_000001_add_review_fields_to_events_table.php'
        );

        // Solo se revierte esta migración dentro de la BD aislada del test.
        $migration->down();

        $event = $this->publishedEvent();

        $before = (array) DB::table('events')
            ->where('id', $event->id)
            ->first();

        $zonesBefore = DB::table('event_zones')
            ->where('event_id', $event->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($zone) => (array) $zone)
            ->all();

        $migration->up();

        $after = (array) DB::table('events')
            ->where('id', $event->id)
            ->first();

        $this->assertFalse((bool) $after['queue_enabled']);
        $this->assertNull($after['rejection_reason']);
        $this->assertNull($after['reviewed_at']);
        $this->assertNull($after['reviewed_by']);

        unset(
            $after['queue_enabled'],
            $after['rejection_reason'],
            $after['reviewed_at'],
            $after['reviewed_by'],
        );

        $this->assertSame($before, $after);

        $zonesAfter = DB::table('event_zones')
            ->where('event_id', $event->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($zone) => (array) $zone)
            ->all();

        $this->assertSame($zonesBefore, $zonesAfter);
    }
}

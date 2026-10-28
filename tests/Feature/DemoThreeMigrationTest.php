<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class DemoThreeMigrationTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    public function test_new_migrations_can_be_rolled_back_and_reapplied_without_touching_users(): void
    {
        $this->seedDemo();
        $user = $this->actor();
        $files = [
            '2026_10_25_000001_create_venues_and_venue_zones_tables.php',
            '2026_10_25_000002_add_predefined_venues_and_banners_to_events.php',
            '2026_10_25_000003_create_favorites_table.php',
            '2026_10_25_000004_add_ticket_snapshots.php',
            '2026_10_28_000001_add_frontend_contract_support.php',
        ];
        foreach (array_reverse($files) as $file) {
            (require database_path('migrations/'.$file))->down();
        }
        $this->assertFalse(Schema::hasTable('venues'));
        foreach ($files as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->assertTrue(Schema::hasTable('venues'));
        $this->assertTrue(Schema::hasTable('event_banners'));
        $this->assertTrue(Schema::hasTable('favorites'));
        $this->assertTrue(Schema::hasColumn('tickets', 'event_title'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_existing_aggregate_sale_is_individualized_without_losing_code_revenue_or_stock(): void
    {
        $this->seedDemo();
        $migration = require database_path('migrations/2026_10_25_000004_add_ticket_snapshots.php');
        $migration->down();
        $event = $this->publishedEvent();
        $zone = $event->zones->first();
        $zone->update(['available_capacity' => 97]);
        $buyer = $this->actor();
        $id = DB::table('tickets')->insertGetId([
            'user_id' => $buyer->id, 'event_zone_id' => $zone->id, 'quantity' => 3,
            'total_price' => 10, 'code' => 'legacy-code', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration->up();

        $this->assertDatabaseCount('tickets', 3);
        $this->assertDatabaseHas('tickets', ['id' => $id, 'code' => 'legacy-code', 'quantity' => 1]);
        $this->assertEquals(10, DB::table('tickets')->sum('total_price'));
        $this->assertEquals(3, DB::table('tickets')->sum('quantity'));
        $this->assertSame(97, $zone->fresh()->available_capacity);
        $this->assertDatabaseHas('tickets', ['id' => $id, 'event_title' => $event->title, 'zone_name' => $zone->name]);
        $this->getJson('/api/v1/tickets/legacy-code/validacion')->assertOk();
    }
}

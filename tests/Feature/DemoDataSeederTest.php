<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VenueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): void
    {
        $this->seed([RoleSeeder::class, VenueSeeder::class, DemoDataSeeder::class]);
    }

    public function test_seeds_the_demo_client_and_organizer_with_profiles(): void
    {
        $this->seedDemo();

        $client = User::where('email', 'cliente@tkt.pe')->sole();
        $this->assertSame('CLIENT', $client->role->name);
        $this->assertTrue($client->active);
        $this->assertSame('12345678', $client->clientProfile->doc_number);

        $organizer = User::where('email', 'organizador@tkt.pe')->sole();
        $this->assertSame('ORGANIZER', $organizer->role->name);
        $this->assertTrue($organizer->active);
        $this->assertSame('20123456789', $organizer->organizerProfile->tax_id);
        $this->assertSame('Ames Producciones', $organizer->organizerProfile->details['displayName']);
    }

    public function test_seeds_sixteen_published_events_with_future_relative_dates(): void
    {
        $this->seedDemo();

        $events = Event::orderBy('starts_at')->get();

        $this->assertCount(16, $events);
        $this->assertSame(16, $events->where('status', 'PUBLISHED')->count());
        $this->assertTrue($events->every(fn (Event $event) => $event->starts_at->isFuture()));
        $this->assertTrue($events->every(fn (Event $event) => filled($event->image_url)));
        $this->assertSame(52, $events->sum(fn (Event $event) => $event->zones->count()));

        $first = $events->first();
        $last = $events->last();
        $this->assertTrue($first->starts_at->greaterThan(now()->addDays(6)));
        $this->assertTrue($first->starts_at->lessThan(now()->addDays(8)));
        $this->assertSame(78660, (int) $first->starts_at->diffInMinutes($last->starts_at));
    }

    public function test_event_zones_follow_the_venue_catalog(): void
    {
        $this->seedDemo();

        $sporting = Event::where('title', 'Sporting Cristal vs Universitario — Fútbol')->sole();
        $sporting->load('venue.zones');
        $catalog = $sporting->venue->zones->keyBy('name');

        $this->assertCount(10, $sporting->zones);
        $this->assertTrue($sporting->zones->every(fn ($zone) => $zone->venue_zone_id !== null));
        $this->assertTrue($sporting->zones->every(fn ($zone) => $catalog->has($zone->name)));
        $this->assertTrue($sporting->zones->every(
            fn ($zone) => (int) $catalog[$zone->name]->capacity === (int) $zone->capacity
                && (int) $zone->capacity === (int) $zone->available_capacity
        ));
    }

    public function test_keeps_the_fair_zones_that_are_not_in_the_venue_catalog(): void
    {
        $this->seedDemo();

        $fair = Event::where('title', 'Sabores del Perú — Feria Gastronómica')->sole();
        $zones = $fair->zones->keyBy('name');

        $this->assertSame('Explanada Costa Verde', $fair->venue_name);
        $this->assertCount(2, $fair->zones);
        $this->assertTrue($fair->zones->every(fn ($zone) => $zone->venue_zone_id === null));
        $this->assertSame(8000, (int) $zones['Entrada general']->capacity);
        $this->assertSame(1500, (int) $zones['Pase degustación']->capacity);
        $this->assertSame(45.0, (float) $zones['Entrada general']->base_price);
        $this->assertSame(130.0, (float) $zones['Pase degustación']->base_price);
    }

    public function test_seeding_twice_keeps_the_same_rows(): void
    {
        $this->seedDemo();
        $this->seedDemo();

        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('venues', 16);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('events', 16);
        $this->assertDatabaseCount('event_zones', 52);
    }

    public function test_seeds_when_the_demo_documents_are_already_taken(): void
    {
        $this->seed(RoleSeeder::class);
        User::factory()->create()->clientProfile()->create(['doc_number' => '12345678']);
        User::factory()->create([
            'role_id' => Role::where('name', 'ORGANIZER')->value('id'),
        ])->organizerProfile()->create(['tax_id' => '20123456789']);

        $this->seedDemo();

        $client = User::where('email', 'cliente@tkt.pe')->sole();
        $this->assertSame('87654321', $client->clientProfile->doc_number);

        $organizer = User::where('email', 'organizador@tkt.pe')->sole();
        $this->assertSame('20555123456', $organizer->organizerProfile->tax_id);

        $this->assertDatabaseCount('events', 16);
    }

    public function test_skips_the_demo_profile_when_no_document_is_available(): void
    {
        $this->seed(RoleSeeder::class);

        foreach (['12345678', '87654321', '45671234', '70000001'] as $docNumber) {
            User::factory()->create()->clientProfile()->create(['doc_number' => $docNumber]);
        }

        $this->seedDemo();

        $client = User::where('email', 'cliente@tkt.pe')->sole();
        $this->assertNull($client->clientProfile);
        $this->assertDatabaseCount('events', 16);
    }

    public function test_seeds_an_existing_demo_user_without_a_profile(): void
    {
        $this->seed(RoleSeeder::class);
        $client = User::factory()->create([
            'email' => 'cliente@tkt.pe',
            'full_name' => 'Juan Aguilar',
        ]);
        $this->assertNull($client->clientProfile);

        $this->seedDemo();

        $this->assertSame('12345678', $client->fresh()->clientProfile->doc_number);
        $this->assertDatabaseCount('events', 16);
    }
}

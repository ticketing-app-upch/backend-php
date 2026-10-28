<?php

namespace Tests\Concerns;

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VenueSeeder;
use Illuminate\Http\UploadedFile;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

trait BuildsDemoThreeFixtures
{
    protected function seedDemo(): void
    {
        $this->seed([RoleSeeder::class, VenueSeeder::class]);
        $this->travelTo(now()->setDate(2026, 10, 25)->startOfDay());
    }

    protected function actor(string $role = 'CLIENT', bool $active = true): User
    {
        return User::factory()->create([
            'role_id' => Role::where('name', $role)->value('id'),
            'active' => $active,
        ]);
    }

    protected function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    protected function bannerFile(string $name = 'banner.jpg'): UploadedFile
    {
        return new UploadedFile(base_path('tests/Fixtures/'.$name), $name, 'image/jpeg', null, true);
    }

    protected function eventPayload(array $overrides = []): array
    {
        $venue = Venue::with('zones')->firstOrFail();

        return array_replace([
            'title' => 'Concierto Demo 3', 'description' => 'Descripción del evento.',
            'category' => 'CONCIERTO', 'venueId' => $venue->id,
            'startsAt' => now()->addMonth()->toIso8601String(), 'endsAt' => null,
            'banner' => $this->bannerFile(),
            'zones' => $venue->zones->map(fn ($zone) => [
                'venueZoneId' => $zone->id, 'basePrice' => 50,
            ])->all(),
        ], $overrides);
    }

    protected function publishedEvent(array $overrides = []): Event
    {
        $venue = Venue::with('zones')->firstOrFail();
        $event = Event::create(array_replace([
            'organizer_id' => $this->actor('ORGANIZER')->id, 'venue_id' => $venue->id,
            'title' => 'Evento Demo 3', 'description' => 'Descripción de prueba.',
            'category' => 'CONCIERTO', 'venue_name' => $venue->name,
            'venue_address' => $venue->address, 'venue_capacity' => $venue->capacity,
            'starts_at' => now()->addMonth(), 'status' => 'PUBLISHED',
        ], $overrides));
        foreach ($venue->zones as $index => $zone) {
            $event->zones()->create([
                'venue_zone_id' => $zone->id, 'name' => $zone->name,
                'capacity' => ($index + 1) * 100, 'available_capacity' => ($index + 1) * 100,
                'base_price' => 50,
            ]);
        }

        return $event;
    }

    protected function payment(array $overrides = []): array
    {
        return array_replace([
            'cardNumber' => '4111111111111111', 'cvv' => '123',
            'expiresAt' => now()->addYear()->toDateString(),
        ], $overrides);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $roleName): User
    {
        return User::factory()->create([
            'role_id' => Role::where('name', $roleName)->value('id'),
        ]);
    }

    private function eventFor(User $organizer, string $title = 'Evento de prueba'): Event
    {
        return Event::create([
            'organizer_id' => $organizer->id,
            'title' => $title,
            'description' => 'Descripción del evento.',
            'category' => 'CONCIERTO',
            'venue_name' => 'Estadio Nacional',
            'venue_address' => 'Lima',
            'venue_capacity' => 1000,
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addHours(3),
            'status' => 'PUBLISHED',
        ]);
    }

    public function test_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/events/1/sales-report')->assertStatus(401);
    }

    public function test_client_devuelve_403(): void
    {
        $client = $this->userWithRole('CLIENT');
        $token = auth('api')->login($client);

        $this->getJson('/api/events/1/sales-report', [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(403);
    }

    public function test_evento_inexistente_devuelve_404(): void
    {
        $organizer = $this->userWithRole('ORGANIZER');
        $token = auth('api')->login($organizer);

        $this->getJson('/api/events/999/sales-report', [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(404);
    }

    public function test_organizador_no_puede_consultar_evento_ajeno(): void
    {
        $owner = $this->userWithRole('ORGANIZER');
        $otherOrganizer = $this->userWithRole('ORGANIZER');
        $event = $this->eventFor($owner);
        $token = auth('api')->login($otherOrganizer);

        $this->getJson("/api/events/{$event->id}/sales-report", [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(403);
    }

    public function test_evento_sin_ventas_devuelve_totales_en_cero(): void
    {
        $organizer = $this->userWithRole('ORGANIZER');
        $event = $this->eventFor($organizer);
        $event->zones()->create([
            'name' => 'General',
            'capacity' => 800,
            'available_capacity' => 800,
            'base_price' => 50,
        ]);
        $token = auth('api')->login($organizer);

        $this->getJson("/api/events/{$event->id}/sales-report", [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->assertJsonPath('summary.totalRevenue', 0)
            ->assertJsonPath('summary.ticketsSold', 0)
            ->assertJsonPath('summary.occupancyPercentage', 0)
            ->assertJsonPath('zones.0.revenue', 0)
            ->assertJsonPath('zones.0.ticketsSold', 0);
    }

    public function test_reporte_calcula_totales_y_ventas_por_zona(): void
    {
        $organizer = $this->userWithRole('ORGANIZER');
        $client = $this->userWithRole('CLIENT');
        $event = $this->eventFor($organizer);

        $general = $event->zones()->create([
            'name' => 'General',
            'capacity' => 800,
            'available_capacity' => 798,
            'base_price' => 50,
        ]);
        $vip = $event->zones()->create([
            'name' => 'VIP',
            'capacity' => 200,
            'available_capacity' => 199,
            'base_price' => 150,
        ]);

        Ticket::create([
            'user_id' => $client->id,
            'event_zone_id' => $general->id,
            'quantity' => 2,
            'total_price' => 100,
            'code' => Str::uuid()->toString(),
        ]);
        Ticket::create([
            'user_id' => $client->id,
            'event_zone_id' => $vip->id,
            'quantity' => 1,
            'total_price' => 150,
            'code' => Str::uuid()->toString(),
        ]);

        $token = auth('api')->login($organizer);

        $this->getJson("/api/events/{$event->id}/sales-report", [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('event.id', (string) $event->id)
            ->assertJsonPath('summary.totalRevenue', 250)
            ->assertJsonPath('summary.ticketsSold', 3)
            ->assertJsonPath('summary.totalCapacity', 1000)
            ->assertJsonPath('summary.availableCapacity', 997)
            ->assertJsonPath('summary.occupancyPercentage', 0.3)
            ->assertJsonPath('zones.0.name', 'General')
            ->assertJsonPath('zones.0.ticketsSold', 2)
            ->assertJsonPath('zones.0.revenue', 100)
            ->assertJsonPath('zones.0.occupancyPercentage', 0.25)
            ->assertJsonPath('zones.1.name', 'VIP')
            ->assertJsonPath('zones.1.ticketsSold', 1)
            ->assertJsonPath('zones.1.revenue', 150)
            ->assertJsonPath('zones.1.occupancyPercentage', 0.5);
    }
}

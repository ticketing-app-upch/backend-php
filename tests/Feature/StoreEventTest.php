<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function tokenFor(string $roleName): string
    {
        $user = User::factory()->create([
            'role_id' => Role::where('name', $roleName)->value('id'),
        ]);

        return auth('api')->login($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Concierto de prueba',
            'description' => 'Descripción del evento de prueba.',
            'category' => 'CONCIERTO',
            'venueName' => 'Estadio Nacional',
            'venueAddress' => 'Av. José Díaz s/n, Lima',
            'venueCapacity' => 1000,
            'startsAt' => now()->addYear()->toIso8601String(),
            'endsAt' => now()->addYear()->addHours(3)->toIso8601String(),
            'zones' => [
                ['name' => 'Zona A', 'capacity' => 400, 'basePrice' => 120.50],
                ['name' => 'Zona B', 'capacity' => 300, 'basePrice' => 80],
            ],
        ], $overrides);
    }

    public function test_organizer_crea_evento_devuelve_201(): void
    {
        $token = $this->tokenFor('ORGANIZER');

        $response = $this->postJson('/api/events', $this->validPayload(), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201);
    }

    public function test_sin_token_devuelve_401(): void
    {
        $response = $this->postJson('/api/events', $this->validPayload());

        $response->assertStatus(401);
    }

    public function test_client_devuelve_403(): void
    {
        $token = $this->tokenFor('CLIENT');

        $response = $this->postJson('/api/events', $this->validPayload(), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_campos_obligatorios_faltantes_devuelve_422(): void
    {
        $token = $this->tokenFor('ORGANIZER');

        $response = $this->postJson('/api/events', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'title', 'description', 'category', 'venueName',
                'venueAddress', 'venueCapacity', 'startsAt', 'zones',
            ]);
    }

    public function test_sin_zonas_devuelve_422(): void
    {
        $token = $this->tokenFor('ORGANIZER');

        $response = $this->postJson('/api/events', $this->validPayload(['zones' => []]), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['zones']);
    }

    public function test_capacidad_de_zona_menor_o_igual_a_cero_devuelve_422(): void
    {
        $token = $this->tokenFor('ORGANIZER');

        $response = $this->postJson('/api/events', $this->validPayload([
            'zones' => [
                ['name' => 'Zona A', 'capacity' => 0, 'basePrice' => 50],
            ],
        ]), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['zones.0.capacity']);
    }

    public function test_precio_negativo_devuelve_422(): void
    {
        $token = $this->tokenFor('ORGANIZER');

        $response = $this->postJson('/api/events', $this->validPayload([
            'zones' => [
                ['name' => 'Zona A', 'capacity' => 100, 'basePrice' => -10],
            ],
        ]), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['zones.0.basePrice']);
    }

    public function test_nombres_de_zonas_duplicados_devuelve_422(): void
    {
        $token = $this->tokenFor('ORGANIZER');

        $response = $this->postJson('/api/events', $this->validPayload([
            'zones' => [
                ['name' => 'Zona A', 'capacity' => 100, 'basePrice' => 50],
                ['name' => 'zona a', 'capacity' => 100, 'basePrice' => 50],
            ],
        ]), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['zones']);
    }

    public function test_suma_de_zonas_mayor_a_venue_capacity_devuelve_422(): void
    {
        $token = $this->tokenFor('ORGANIZER');

        $response = $this->postJson('/api/events', $this->validPayload([
            'venueCapacity' => 100,
            'zones' => [
                ['name' => 'Zona A', 'capacity' => 80, 'basePrice' => 50],
                ['name' => 'Zona B', 'capacity' => 50, 'basePrice' => 50],
            ],
        ]), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['venueCapacity']);
    }

    public function test_ends_at_anterior_a_starts_at_devuelve_422(): void
    {
        $token = $this->tokenFor('ORGANIZER');

        $response = $this->postJson('/api/events', $this->validPayload([
            'startsAt' => now()->addYear()->toIso8601String(),
            'endsAt' => now()->addYear()->subHour()->toIso8601String(),
        ]), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['endsAt']);
    }

    public function test_available_capacity_se_inicializa_igual_a_capacity(): void
    {
        $token = $this->tokenFor('ORGANIZER');

        $response = $this->postJson('/api/events', $this->validPayload(), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201);

        $zones = $response->json('zones');

        foreach ($zones as $zone) {
            $this->assertSame($zone['capacity'], $zone['availableCapacity']);
        }

        $this->assertDatabaseHas('event_zones', [
            'name' => 'Zona A',
            'capacity' => 400,
            'available_capacity' => 400,
        ]);
    }

    public function test_evento_queda_asociado_al_organizador_autenticado(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::where('name', 'ORGANIZER')->value('id'),
        ]);
        $token = auth('api')->login($user);

        $response = $this->postJson('/api/events', $this->validPayload(), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('events', [
            'id' => $response->json('id'),
            'organizer_id' => $user->id,
        ]);
    }

    public function test_evento_y_zonas_se_persisten_correctamente(): void
    {
        $token = $this->tokenFor('ORGANIZER');

        $response = $this->postJson('/api/events', $this->validPayload(), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('events', [
            'id' => $response->json('id'),
            'title' => 'Concierto de prueba',
            'category' => 'CONCIERTO',
            'venue_name' => 'Estadio Nacional',
            'venue_capacity' => 1000,
            'status' => 'PUBLISHED',
        ]);

        $this->assertDatabaseHas('event_zones', [
            'event_id' => $response->json('id'),
            'name' => 'Zona A',
            'capacity' => 400,
            'base_price' => 120.50,
        ]);

        $this->assertDatabaseHas('event_zones', [
            'event_id' => $response->json('id'),
            'name' => 'Zona B',
            'capacity' => 300,
            'base_price' => 80.00,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IndexEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<int, array<string, mixed>>  $zones
     */
    private function createEvent(array $overrides = [], array $zones = [
        ['name' => 'Zona A', 'capacity' => 100, 'available_capacity' => 100, 'base_price' => 50],
    ]): Event
    {
        $organizer = User::factory()->create([
            'role_id' => Role::where('name', 'ORGANIZER')->value('id'),
        ]);

        $event = Event::create(array_replace([
            'organizer_id' => $organizer->id,
            'title' => 'Evento de prueba',
            'description' => 'Descripción de prueba.',
            'category' => 'DEPORTE',
            'venue_name' => 'Estadio Nacional',
            'venue_address' => 'Av. José Díaz s/n, Lima',
            'venue_capacity' => 1000,
            'starts_at' => now()->addDays(10),
            'ends_at' => null,
            'status' => 'PUBLISHED',
        ], $overrides));

        foreach ($zones as $zone) {
            $event->zones()->create($zone);
        }

        return $event;
    }

    public function test_sin_autenticacion_devuelve_200(): void
    {
        $response = $this->getJson('/api/events');

        $response->assertStatus(200);
    }

    public function test_devuelve_eventos_published_futuros(): void
    {
        $event = $this->createEvent(['starts_at' => now()->addDays(5)]);

        $response = $this->getJson('/api/events');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => (string) $event->id]);
    }

    public function test_no_devuelve_eventos_pasados(): void
    {
        $pastEvent = $this->createEvent(['starts_at' => now()->subDays(2)]);

        $response = $this->getJson('/api/events');

        $response->assertStatus(200)
            ->assertJsonMissing(['id' => (string) $pastEvent->id]);
    }

    public function test_no_devuelve_eventos_con_status_diferente_de_published(): void
    {
        $draftEvent = $this->createEvent(['status' => 'DRAFT', 'starts_at' => now()->addDays(5)]);

        $response = $this->getJson('/api/events');

        $response->assertStatus(200)
            ->assertJsonMissing(['id' => (string) $draftEvent->id]);
    }

    public function test_ordena_por_starts_at_asc(): void
    {
        $later = $this->createEvent(['starts_at' => now()->addDays(20)]);
        $sooner = $this->createEvent(['starts_at' => now()->addDays(2)]);

        $response = $this->getJson('/api/events');
        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertSame([(string) $sooner->id, (string) $later->id], $ids);
    }

    public function test_filtra_por_category(): void
    {
        $deporte = $this->createEvent(['category' => 'DEPORTE', 'starts_at' => now()->addDays(3)]);
        $concierto = $this->createEvent(['category' => 'CONCIERTO', 'starts_at' => now()->addDays(4)]);

        $response = $this->getJson('/api/events?category=deporte');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => (string) $deporte->id])
            ->assertJsonMissing(['id' => (string) $concierto->id]);
    }

    public function test_filtra_parcialmente_por_venue_name(): void
    {
        $match = $this->createEvent([
            'venue_name' => 'Estadio de Lima Norte',
            'venue_address' => 'Otra dirección 1',
            'starts_at' => now()->addDays(3),
        ]);
        $noMatch = $this->createEvent([
            'venue_name' => 'Coliseo Arequipa',
            'venue_address' => 'Otra dirección 2',
            'starts_at' => now()->addDays(4),
        ]);

        $response = $this->getJson('/api/events?venue=lima');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => (string) $match->id])
            ->assertJsonMissing(['id' => (string) $noMatch->id]);
    }

    public function test_filtra_parcialmente_por_venue_address(): void
    {
        $match = $this->createEvent([
            'venue_name' => 'Coliseo Central',
            'venue_address' => 'Jr. Lima 123',
            'starts_at' => now()->addDays(3),
        ]);
        $noMatch = $this->createEvent([
            'venue_name' => 'Otro recinto',
            'venue_address' => 'Av. Arequipa 456',
            'starts_at' => now()->addDays(4),
        ]);

        $response = $this->getJson('/api/events?venue=lima');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => (string) $match->id])
            ->assertJsonMissing(['id' => (string) $noMatch->id]);
    }

    public function test_filtra_por_date(): void
    {
        $target = $this->createEvent(['starts_at' => '2026-10-10 15:00:00']);
        $other = $this->createEvent(['starts_at' => '2026-10-11 15:00:00']);

        $response = $this->getJson('/api/events?date=2026-10-10');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => (string) $target->id])
            ->assertJsonMissing(['id' => (string) $other->id]);
    }

    public function test_permite_combinar_filtros(): void
    {
        $match = $this->createEvent([
            'category' => 'DEPORTE',
            'venue_name' => 'Estadio de Lima',
            'venue_address' => 'Dirección X',
            'starts_at' => '2026-11-05 18:00:00',
        ]);
        $wrongCategory = $this->createEvent([
            'category' => 'CONCIERTO',
            'venue_name' => 'Estadio de Lima',
            'venue_address' => 'Dirección Y',
            'starts_at' => '2026-11-05 18:00:00',
        ]);
        $wrongDate = $this->createEvent([
            'category' => 'DEPORTE',
            'venue_name' => 'Estadio de Lima',
            'venue_address' => 'Dirección Z',
            'starts_at' => '2026-11-06 18:00:00',
        ]);

        $response = $this->getJson('/api/events?category=DEPORTE&venue=lima&date=2026-11-05');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => (string) $match->id])
            ->assertJsonMissing(['id' => (string) $wrongCategory->id])
            ->assertJsonMissing(['id' => (string) $wrongDate->id]);
    }

    public function test_date_invalido_devuelve_422(): void
    {
        $response = $this->getJson('/api/events?date=10-10-2026');

        $response->assertStatus(422)->assertJsonValidationErrors(['date']);
    }

    public function test_sin_resultados_devuelve_200_con_array_vacio(): void
    {
        $response = $this->getJson('/api/events?category=INEXISTENTE');

        $response->assertStatus(200)->assertExactJson([]);
    }

    public function test_la_respuesta_incluye_las_zonas(): void
    {
        $event = $this->createEvent([], [
            ['name' => 'Zona A', 'capacity' => 100, 'available_capacity' => 100, 'base_price' => 50],
            ['name' => 'Zona B', 'capacity' => 50, 'available_capacity' => 50, 'base_price' => 30],
        ]);

        $response = $this->getJson('/api/events');
        $response->assertStatus(200);

        $payload = collect($response->json())->firstWhere('id', (string) $event->id);

        $this->assertCount(2, $payload['zones']);
        $this->assertEqualsCanonicalizing(
            ['Zona A', 'Zona B'],
            collect($payload['zones'])->pluck('name')->all()
        );
    }

    public function test_no_incurre_en_problema_n_mas_1(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createEvent(['starts_at' => now()->addDays(10 + $i)]);
        }

        DB::enableQueryLog();
        $response = $this->getJson('/api/events');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);

        $zoneQueries = collect($queries)->filter(
            fn ($query) => str_contains($query['query'], 'event_zones')
        );

        $this->assertCount(
            1,
            $zoneQueries,
            'Se esperaba una única query para cargar las zonas (eager loading), evitando N+1.'
        );
    }
}

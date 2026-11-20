<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class MarketDashboardSellOutTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_average_uses_only_observed_sellouts_and_reports_its_sample_size(): void
    {
        // Dos duraciones observadas: 2 y 6 horas. Promedio: 4 horas.
        $published = now()->subHours(8)->toDateTimeString();
        $this->measurement($published, now()->subHours(6)->toDateTimeString());
        $this->measurement($published, now()->subHours(2)->toDateTimeString());
        $this->measurement($published, null);

        // Stock histórico agotado, pero sin fecha registrada: no se inventa una.
        $historical = $this->measurement($published, null);
        $historical->zones()->update(['available_capacity' => 0]);

        // Un rechazado con fechas no debe entrar a ninguna métrica de mercado.
        $this->measurement($published, now()->subHour()->toDateTimeString(), ['status' => 'REJECTED']);

        $response = $this->getJson('/api/v1/dashboard/mercado', $this->headersFor($this->actor('ORGANIZER')));
        $response->assertOk()
            ->assertJsonPath('sellOutTime.available', true)
            ->assertJsonPath('sellOutTime.sampleSize', 2)
            ->assertJsonPath('sellOutTime.publishedEventCount', 4)
            ->assertJsonPath('sellOutTime.eventsWithoutMeasurement', 2)
            ->assertJsonPath('sellOutTime.reason', null);
        $this->assertEquals(4, $response->json('sellOutTime.averageHours'));
        $this->assertNull($historical->fresh()->first_sold_out_at);
    }

    public function test_zero_hours_is_a_valid_observation(): void
    {
        $time = now()->subHour()->toDateTimeString();
        $this->measurement($time, $time);
        $response = $this->getJson('/api/v1/dashboard/mercado', $this->headersFor($this->actor('ORGANIZER')));
        $response->assertOk()->assertJsonPath('sellOutTime.available', true)
            ->assertJsonPath('sellOutTime.sampleSize', 1);
        $this->assertEquals(0, $response->json('sellOutTime.averageHours'));
    }

    public static function invalidObservations(): array
    {
        return [
            'missing publication' => [null, -1],
            'missing sellout' => [-4, null],
            'sellout before publication' => [-1, -4],
            'future sellout' => [-4, 1],
        ];
    }

    #[DataProvider('invalidObservations')]
    public function test_incomplete_or_invalid_observations_are_excluded(?int $publicationOffset, ?int $selloutOffset): void
    {
        $this->measurement(
            $publicationOffset === null ? null : now()->addHours($publicationOffset)->toDateTimeString(),
            $selloutOffset === null ? null : now()->addHours($selloutOffset)->toDateTimeString(),
        );
        $this->getJson('/api/v1/dashboard/mercado', $this->headersFor($this->actor('ORGANIZER')))
            ->assertOk()->assertJsonPath('empty', false)
            ->assertJsonPath('sellOutTime.available', false)
            ->assertJsonPath('sellOutTime.averageHours', null)
            ->assertJsonPath('sellOutTime.sampleSize', 0)
            ->assertJsonPath('sellOutTime.publishedEventCount', 1)
            ->assertJsonPath('sellOutTime.eventsWithoutMeasurement', 1);
    }

    public function test_combined_market_filters_also_filter_sellout_observations(): void
    {
        [$venueA, $venueB] = Venue::orderBy('id')->take(2)->get();
        $published = now()->subHours(20)->toDateTimeString();
        $base = [
            'category' => 'CONCIERTO', 'queue_enabled' => true,
            'starts_at' => '2026-11-10 18:00:00',
            'venue_id' => $venueA->id, 'venue_name' => $venueA->name,
        ];
        $this->measurement($published, now()->subHours(18)->toDateTimeString(), $base);
        foreach ([
            ['category' => 'TEATRO'],
            ['queue_enabled' => false],
            ['starts_at' => '2026-11-11 18:00:00'],
            ['venue_id' => $venueB->id, 'venue_name' => $venueB->name],
        ] as $different) {
            $this->measurement($published, now()->subHours(2)->toDateTimeString(), array_replace($base, $different));
        }

        $filters = http_build_query([
            'dateFrom' => '2026-11-10', 'dateTo' => '2026-11-10',
            'category' => 'concierto', 'venueId' => $venueA->id, 'queueEnabled' => 1,
        ]);
        $response = $this->getJson('/api/v1/dashboard/mercado?'.$filters, $this->headersFor($this->actor('ORGANIZER')));
        $response->assertOk()->assertJsonPath('summary.eventCount', 1)
            ->assertJsonPath('sellOutTime.sampleSize', 1)
            ->assertJsonPath('sellOutTime.publishedEventCount', 1);
        $this->assertEquals(2, $response->json('sellOutTime.averageHours'));
    }

    public function test_an_actual_purchase_supplies_the_dashboard_measurement(): void
    {
        $event = $this->measurement(now()->subHours(4)->toDateTimeString(), null);
        $event->zones()->delete();
        $zone = $event->zones()->create([
            'name' => 'Zona unica QA', 'capacity' => 1,
            'available_capacity' => 1, 'base_price' => 50,
        ]);
        $event->update(['venue_capacity' => 1]);
        $this->postJson('/api/orders', [
            'eventId' => $event->id,
            'items' => [['zoneId' => $zone->id, 'quantity' => 1]],
        ], $this->headersFor($this->actor('CLIENT')))->assertCreated();

        $response = $this->getJson('/api/v1/dashboard/mercado', $this->headersFor($this->actor('ORGANIZER')));
        $response->assertOk()->assertJsonPath('sellOutTime.available', true)
            ->assertJsonPath('sellOutTime.sampleSize', 1);
        $this->assertEquals(4, $response->json('sellOutTime.averageHours'));
    }

    private function measurement(?string $publishedAt, ?string $soldOutAt, array $overrides = []): Event
    {
        $event = $this->publishedEvent(array_replace(['published_at' => $publishedAt], $overrides));
        // Datos de prueba del servidor: el hito no es asignable por formularios.
        $event->forceFill(['first_sold_out_at' => $soldOutAt])->save();
        if ($soldOutAt !== null) {
            $event->zones()->update(['available_capacity' => 0]);
        }

        return $event;
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class MarketDashboardMetricsTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_market_totals_rankings_privacy_and_local_sale_dates(): void
    {
        $venueA = $this->marketVenue('QA Mercado A', 100);
        $venueB = $this->marketVenue('QA Mercado B', 200);
        $a = $this->marketEvent($venueA, 'CONCIERTO', true, '2026-11-01 18:00:00', [40, 60]);
        $b = $this->marketEvent($venueB, 'TEATRO', false, '2026-11-02 18:00:00', [200]);
        $this->marketEvent($venueA, 'CONCIERTO', false, '2026-09-01 18:00:00', [100]);

        // Dos zonas del mismo evento: no deben multiplicar capacidad ni ingresos.
        $this->sale($a, 1, 10000, 600, '2026-10-01 04:30:00', zoneIndex: 0);
        $this->sale($a, 1, 10000, 600, '2026-10-01 04:30:00', zoneIndex: 1);
        $this->sale($b, 3, 5000, 300, '2026-10-01 05:30:00');
        $this->sale($a, 5, 90000, 5400, '2026-10-02 12:00:00', 'CANCELADA');

        foreach (['PENDING_APPROVAL', 'REJECTED', 'DRAFT'] as $status) {
            $hidden = $this->marketEvent($venueA, 'PRIVADO', false, '2026-12-01 18:00:00', [100], $status);
            $this->sale($hidden, 1, 99900, 5994, '2026-10-03 12:00:00');
        }

        $before = $this->databaseSnapshot();
        // El consultante no tiene eventos: debe ver los agregados del mercado.
        $response = $this->getJson('/api/v1/dashboard/mercado', $this->headersFor($this->actor('ORGANIZER')));
        $response->assertOk()
            ->assertJsonPath('empty', false)
            ->assertJsonPath('summary.eventCount', 3)
            ->assertJsonPath('summary.capacity', 400)
            ->assertJsonPath('summary.ticketsSold', 5)
            ->assertJsonPath('summary.averageOccupancyPercentage', 1.17)
            ->assertJsonPath('topVenues.byEvents.0.venueId', (string) $venueA->id)
            ->assertJsonPath('topVenues.byEvents.0.eventCount', 2)
            ->assertJsonPath('topVenues.byTickets.0.venueId', (string) $venueB->id)
            ->assertJsonPath('topVenues.byRevenue.0.venueId', (string) $venueA->id)
            ->assertJsonPath('topCategories.0.category', 'TEATRO')
            ->assertJsonPath('categories.0.category', 'CONCIERTO')
            ->assertJsonPath('categories.0.averageOccupancyPercentage', 1)
            ->assertJsonPath('categories.1.averageOccupancyPercentage', 1.5)
            ->assertJsonCount(2, 'monthlySales')
            ->assertJsonPath('monthlySales.0.month', '2026-09')
            ->assertJsonPath('monthlySales.0.ticketsSold', 2)
            ->assertJsonPath('monthlySales.1.month', '2026-10')
            ->assertJsonPath('monthlySales.1.ticketsSold', 3)
            ->assertJsonCount(2, 'salesHeatmap')
            ->assertJsonPath('salesHeatmap.0.category', 'CONCIERTO')
            ->assertJsonPath('salesHeatmap.0.weekday', 3)
            ->assertJsonPath('salesHeatmap.1.category', 'TEATRO')
            ->assertJsonPath('salesHeatmap.1.weekday', 4);

        $this->assertEquals(350, $response->json('summary.revenue'));
        $this->assertEquals(21, $response->json('summary.serviceFees'));
        $this->assertEquals(70, $response->json('summary.averageTicketPrice'));
        $this->assertEquals(200, $response->json('monthlySales.0.revenue'));
        $this->assertEquals(150, $response->json('monthlySales.1.revenue'));
        $this->assertEquals(200, $response->json('topVenues.byEvents.0.capacity'));
        $this->assertSame($before, $this->databaseSnapshot());

        $json = $response->getContent();
        foreach (['organizer_id', 'organizerId', 'buyer_name', 'buyerName', 'user_id', 'email', 'Titulo privado QA', 'Comprador privado QA'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public static function marketFilters(): array
    {
        return [
            'all events' => [[], 2, 5, 350],
            'queue disabled is preserved' => [['queueEnabled' => 0], 1, 3, 150],
            'queue enabled' => [['queueEnabled' => 1], 1, 2, 200],
            'category ignores case' => [['category' => 'concierto'], 1, 2, 200],
            'venue' => [['venue' => 'A'], 1, 2, 200],
            'start only' => [['dateFrom' => '2026-11-11'], 1, 3, 150],
            'end only' => [['dateTo' => '2026-11-10'], 1, 2, 200],
            'all filters use AND' => [['venue' => 'A', 'category' => 'CONCIERTO', 'queueEnabled' => 1, 'dateFrom' => '2026-11-10', 'dateTo' => '2026-11-10'], 1, 2, 200],
            'incompatible filters' => [['category' => 'CONCIERTO', 'queueEnabled' => 0], 0, 0, 0],
        ];
    }

    #[DataProvider('marketFilters')]
    public function test_filters_apply_to_summary_rankings_and_sales(array $filters, int $count, int $sold, int $revenue): void
    {
        $venueA = $this->marketVenue('QA Filtro A', 100);
        $venueB = $this->marketVenue('QA Filtro B', 200);
        $a = $this->marketEvent($venueA, 'CONCIERTO', true, '2026-11-10 18:00:00', [100]);
        $b = $this->marketEvent($venueB, 'TEATRO', false, '2026-11-11 18:00:00', [200]);
        $this->sale($a, 2, 10000, 600, '2026-10-01 12:00:00');
        $this->sale($b, 3, 5000, 300, '2026-10-01 12:00:00');
        if (isset($filters['venue'])) {
            $filters['venueId'] = $venueA->id;
            unset($filters['venue']);
        }
        $response = $this->getJson('/api/v1/dashboard/mercado?'.http_build_query($filters), $this->headersFor($this->actor('ORGANIZER')));
        $response->assertOk()->assertJsonPath('summary.eventCount', $count)
            ->assertJsonPath('summary.ticketsSold', $sold)
            ->assertJsonPath('empty', $count === 0);
        $this->assertEquals($revenue, $response->json('summary.revenue'));
        $this->assertEquals($sold, array_sum(array_column($response->json('monthlySales'), 'ticketsSold')));
        $this->assertEquals($revenue, array_sum(array_column($response->json('monthlySales'), 'revenue')));
        $this->assertEquals($sold, array_sum(array_column($response->json('salesHeatmap'), 'ticketsSold')));
        $this->assertEquals($sold, array_sum(array_column($response->json('categories'), 'ticketsSold')));
        $this->assertEquals($revenue, array_sum(array_column($response->json('topVenues.byRevenue'), 'revenue')));
        if (array_key_exists('queueEnabled', $filters)) {
            $response->assertJsonPath('filters.queueEnabled', (bool) $filters['queueEnabled']);
        }
    }

    public function test_date_range_includes_the_whole_lima_day(): void
    {
        $venue = $this->marketVenue('QA Limites Lima', 100);
        foreach ([
            ['2026-11-01 04:59:59', 'EXCLUIDO_ANTES'],
            ['2026-11-01 05:00:00', 'INCLUIDO_INICIO'],
            ['2026-12-01 04:59:59', 'INCLUIDO_FINAL'],
            ['2026-12-01 05:00:00', 'EXCLUIDO_DESPUES'],
        ] as [$date, $category]) {
            $this->marketEvent($venue, $category, false, $date, [100]);
        }
        $this->getJson('/api/v1/dashboard/mercado?dateFrom=2026-11-01&dateTo=2026-11-30', $this->headersFor($this->actor('ORGANIZER')))
            ->assertOk()->assertJsonPath('summary.eventCount', 2)
            ->assertJsonPath('categories.0.category', 'INCLUIDO_FINAL')
            ->assertJsonPath('categories.1.category', 'INCLUIDO_INICIO')
            ->assertJsonCount(2, 'categories');
    }

    public function test_top_five_ties_are_deterministic_and_distribution_keeps_all_categories(): void
    {
        foreach (range(7, 1) as $number) {
            $label = sprintf('QA Ranking %02d', $number);
            $event = $this->marketEvent($this->marketVenue($label, 100), $label, false, '2026-11-10 18:00:00', [100]);
            $this->sale($event, 1, 1000, 60, '2026-10-01 12:00:00');
        }
        $response = $this->getJson('/api/v1/dashboard/mercado', $this->headersFor($this->actor('ORGANIZER')));
        $response->assertOk()->assertJsonCount(5, 'topCategories')
            ->assertJsonCount(7, 'categoryDistribution')
            ->assertJsonCount(7, 'venues')
            ->assertJsonPath('topCategories.0.category', 'QA Ranking 01')
            ->assertJsonPath('topCategories.4.category', 'QA Ranking 05');
        foreach (['byEvents', 'byTickets', 'byRevenue'] as $ranking) {
            $response->assertJsonCount(5, 'topVenues.'.$ranking)
                ->assertJsonPath('topVenues.'.$ranking.'.0.venueName', 'QA Ranking 01')
                ->assertJsonPath('topVenues.'.$ranking.'.4.venueName', 'QA Ranking 05');
        }
    }

    public function test_legacy_ticket_without_order_is_counted_once(): void
    {
        $event = $this->marketEvent($this->marketVenue('QA Historico', 100), 'TEATRO', false, '2026-11-10 18:00:00', [100]);
        $zone = $event->zones()->firstOrFail();
        $zone->tickets()->create([
            'user_id' => $this->actor('CLIENT')->id, 'quantity' => 3,
            'total_price' => 75, 'unit_price' => 25, 'service_fee' => 0,
            'code' => 'QA-'.bin2hex(random_bytes(12)),
        ]);
        $response = $this->getJson('/api/v1/dashboard/mercado', $this->headersFor($this->actor('ORGANIZER')));
        $response->assertOk()->assertJsonPath('summary.ticketsSold', 3);
        $this->assertEquals(75, $response->json('summary.revenue'));
        $this->assertEquals(25, $response->json('summary.averageTicketPrice'));
    }

    private function marketVenue(string $name, int $capacity): Venue
    {
        return Venue::create(['name' => $name, 'address' => 'Direccion QA', 'capacity' => $capacity]);
    }

    private function marketEvent(Venue $venue, string $category, bool $queue, string $startsAt, array $capacities, string $status = 'PUBLISHED'): Event
    {
        $event = Event::create([
            'organizer_id' => $this->actor('ORGANIZER')->id,
            'venue_id' => $venue->id, 'title' => 'Titulo privado QA',
            'description' => 'Descripcion privada del evento de prueba.',
            'category' => $category, 'venue_name' => $venue->name,
            'venue_address' => $venue->address, 'venue_capacity' => array_sum($capacities),
            'starts_at' => $startsAt, 'status' => $status, 'queue_enabled' => $queue,
        ]);
        foreach ($capacities as $index => $capacity) {
            $event->zones()->create([
                'name' => 'Zona '.($index + 1), 'capacity' => $capacity,
                'available_capacity' => $capacity, 'base_price' => 100,
            ]);
        }

        return $event;
    }

    private function sale(Event $event, int $quantity, int $unitCents, int $feeCents, string $purchasedAt, string $status = 'CONFIRMADA', int $zoneIndex = 0): void
    {
        $buyer = $this->actor('CLIENT');
        $zone = $event->zones()->orderBy('id')->get()[$zoneIndex];
        $order = TicketOrder::create([
            'user_id' => $buyer->id, 'event_id' => $event->id,
            'code' => 'QA-'.bin2hex(random_bytes(12)), 'buyer_name' => 'Comprador privado QA',
            'event_name' => $event->title, 'event_starts_at' => $event->starts_at,
            'event_venue' => $event->venue_name, 'status' => $status,
            'payment_method' => 'CARD', 'lines' => [[
                'zoneId' => (string) $zone->id, 'zoneName' => $zone->name,
                'unitPrice' => $unitCents / 100, 'quantity' => $quantity,
            ]],
            'subtotal' => $quantity * $unitCents / 100,
            'fee' => $quantity * $feeCents / 100,
            'total' => $quantity * ($unitCents + $feeCents) / 100,
        ]);
        for ($index = 0; $index < $quantity; $index++) {
            $order->tickets()->create([
                'user_id' => $buyer->id, 'event_zone_id' => $zone->id,
                'quantity' => 1, 'unit_price' => $unitCents / 100,
                'service_fee' => $feeCents / 100, 'total_price' => ($unitCents + $feeCents) / 100,
                'code' => 'QA-'.bin2hex(random_bytes(12)),
                'event_title' => $event->title, 'event_starts_at' => $event->starts_at,
                'venue_name' => $event->venue_name, 'venue_address' => $event->venue_address,
                'zone_name' => $zone->name,
            ]);
        }
        DB::table('tickets')->where('ticket_order_id', $order->id)
            ->update(['created_at' => $purchasedAt, 'updated_at' => $purchasedAt]);
        DB::table('ticket_orders')->where('id', $order->id)
            ->update(['created_at' => $purchasedAt, 'updated_at' => $purchasedAt]);
        if ($status === 'CONFIRMADA') {
            $zone->decrement('available_capacity', $quantity);
        }
    }

    private function databaseSnapshot(): array
    {
        $snapshot = [];
        foreach (['events', 'event_zones', 'tickets', 'ticket_orders'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}

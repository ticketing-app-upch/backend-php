<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class MarketDashboardAccessTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDemo();
    }

    public function test_authentication_is_required(): void
    {
        $this->getJson('/api/v1/dashboard/mercado')
            ->assertUnauthorized();
    }

    public static function forbiddenRoles(): array
    {
        return [
            'client' => ['CLIENT'],
            'admin' => ['ADMIN'],
        ];
    }

    #[DataProvider('forbiddenRoles')]
    public function test_other_roles_cannot_access(string $role): void
    {
        $this->getJson(
            '/api/v1/dashboard/mercado',
            $this->headersFor($this->actor($role)),
        )->assertForbidden();
    }

    public function test_organizer_receives_a_valid_empty_dashboard(): void
    {
        $this->getJson(
            '/api/v1/dashboard/mercado',
            $this->headersFor($this->actor('ORGANIZER')),
        )->assertOk()
            ->assertJsonPath('empty', true)
            ->assertJsonPath('summary.eventCount', 0)
            ->assertJsonPath('summary.capacity', 0)
            ->assertJsonPath('summary.ticketsSold', 0)
            ->assertJsonPath('summary.revenue', 0)
            ->assertJsonPath('summary.serviceFees', 0)
            ->assertJsonPath('summary.averageTicketPrice', null)
            ->assertJsonPath('summary.averageOccupancyPercentage', null)
            ->assertJsonPath('filters.queueEnabled', null)
            ->assertJsonPath('sellOutTime.available', false)
            ->assertJsonPath('sellOutTime.averageHours', null)
            ->assertJsonPath('queueConversion.available', false)
            ->assertJsonPath('queueConversion.conversionPercentage', null)
            ->assertJsonCount(0, 'topVenues.byEvents')
            ->assertJsonCount(0, 'topVenues.byTickets')
            ->assertJsonCount(0, 'topVenues.byRevenue')
            ->assertJsonCount(0, 'topCategories')
            ->assertJsonCount(0, 'venues')
            ->assertJsonCount(0, 'categories')
            ->assertJsonCount(0, 'categoryDistribution')
            ->assertJsonCount(0, 'monthlySales')
            ->assertJsonCount(0, 'salesHeatmap');

        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_orders', 0);
    }

    public static function invalidFilters(): array
    {
        return [
            'invalid start date' => [
                ['dateFrom' => '2026-02-30'],
                'dateFrom',
            ],
            'invalid end date' => [
                ['dateTo' => 'not-a-date'],
                'dateTo',
            ],
            'inverted range' => [
                [
                    'dateFrom' => '2026-12-31',
                    'dateTo' => '2026-01-01',
                ],
                'dateTo',
            ],
            'empty category' => [
                ['category' => ''],
                'category',
            ],
            'oversized category' => [
                ['category' => str_repeat('A', 101)],
                'category',
            ],
            'invalid venue' => [
                ['venueId' => 0],
                'venueId',
            ],
            'nonexistent venue' => [
                ['venueId' => 999999999],
                'venueId',
            ],
            'invalid queue option' => [
                ['queueEnabled' => 'yes'],
                'queueEnabled',
            ],
            'category array' => [
                ['category' => ['CONCIERTO']],
                'category',
            ],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_field_errors(
        array $filters,
        string $field,
    ): void {
        $this->getJson(
            '/api/v1/dashboard/mercado?'.http_build_query($filters),
            $this->headersFor($this->actor('ORGANIZER')),
        )->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_orders', 0);
    }
}

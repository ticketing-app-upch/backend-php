<?php

namespace App\Http\Controllers;

use App\Http\Requests\Dashboard\MarketDashboardRequest;
use App\Queries\MarketDashboardQuery;
use App\Support\VirtualQueue;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class MarketDashboardController extends Controller
{
    public function __invoke(
        MarketDashboardRequest $request,
        MarketDashboardQuery $query,
        VirtualQueue $queue,
    ): JsonResponse {
        $filters = $request->validated();

        $events = $query->events($filters)->map(function ($event) {
            $capacity = (int) $event->capacity;
            $sold = (int) $event->tickets_sold;

            return [
                'id' => (string) $event->id,
                'queueEnabled' => (bool) $event->queue_enabled,
                'venueId' => $event->venue_id === null
                    ? null
                    : (string) $event->venue_id,
                'venueName' => $event->venue_name,
                'category' => $event->category,
                'publishedAt' => $event->published_at,
                'firstSoldOutAt' => $event->first_sold_out_at,
                'capacity' => $capacity,
                'ticketsSold' => $sold,
                'revenueCents' => $this->cents($event->revenue),
                'serviceFeesCents' => $this->cents($event->service_fees),
                'occupancyPercentage' => $capacity > 0
                    ? $sold * 100 / $capacity
                    : 0.0,
            ];
        });

        $venues = $events
            ->groupBy(fn (array $event) => $event['venueId'] !== null
                ? 'id:'.$event['venueId']
                : 'legacy:'.$event['venueName'])
            ->map(function (Collection $group) {
                $first = $group->first();

                return array_merge([
                    'venueId' => $first['venueId'],
                    'venueName' => $first['venueName'],
                ], $this->summarize($group));
            })
            ->values();

        $categories = $events
            ->groupBy('category')
            ->map(fn (Collection $group, string $category) => array_merge(
                ['category' => $category],
                $this->summarize($group),
            ))
            ->sortBy('category')
            ->values();

        $days = $query->salesByDay($filters)->map(fn ($day) => [
            'date' => (string) $day->sale_day,
            'category' => $day->category,
            'ticketsSold' => (int) $day->tickets_sold,
            'revenueCents' => $this->cents($day->revenue),
            'serviceFeesCents' => $this->cents($day->service_fees),
        ]);

        $monthlySales = $days
            ->groupBy(fn (array $day) => substr($day['date'], 0, 7))
            ->map(fn (Collection $group, string $month) => array_merge(
                ['month' => $month],
                $this->summarizeSales($group),
            ))
            ->sortBy('month')
            ->values();

        $heatmap = $days
            ->map(function (array $day) {
                $day['weekday'] = CarbonImmutable::parse(
                    $day['date'],
                    'America/Lima',
                )->dayOfWeekIso;

                return $day;
            })
            ->groupBy(fn (array $day) => json_encode([
                $day['category'],
                $day['weekday'],
            ], JSON_THROW_ON_ERROR))
            ->map(function (Collection $group) {
                $first = $group->first();

                return array_merge([
                    'category' => $first['category'],
                    'weekday' => $first['weekday'],
                ], $this->summarizeSales($group));
            })
            ->sort(function (array $a, array $b) {
                return strcmp($a['category'], $b['category'])
                    ?: $a['weekday'] <=> $b['weekday'];
            })
            ->values();

        $soldCategories = $categories
            ->filter(fn (array $category) => $category['ticketsSold'] > 0)
            ->values();
        $summary = $this->summarize($events);
        $sellOut = $this->sellOutTime($events);
        $conversion = $queue->conversion($events->where('queueEnabled', true)->pluck('id'));
        $frontendGroup = fn (array $group, string $label) => $group + [
            'name' => $group[$label],
            'events' => $group['eventCount'],
            'tickets' => $group['ticketsSold'],
            'occupancy' => $group['averageOccupancyPercentage'] / 100,
            'averageTicket' => $group['averageTicketPrice'],
        ];

        return response()->json([
            'events' => $summary['eventCount'],
            'tickets' => $summary['ticketsSold'],
            'revenue' => $summary['revenue'],
            'occupancy' => ($summary['averageOccupancyPercentage'] ?? 0) / 100,
            'monthly' => $monthlySales->map(fn (array $month) => [
                'label' => $month['month'], 'tickets' => $month['ticketsSold'],
            ])->all(),
            'heatmap' => $heatmap->groupBy('category')->map(function (Collection $days, string $category) {
                $values = array_fill(0, 7, 0);
                foreach ($days as $day) {
                    $values[$day['weekday'] % 7] = $day['ticketsSold'];
                }

                return ['category' => $category, 'days' => $values];
            })->values()->all(),
            'averageSelloutHours' => $sellOut['averageHours'],
            'queue' => array_intersect_key($conversion, array_flip(['completed', 'expired', 'cancelled'])),
            'filters' => [
                'dateFrom' => $filters['dateFrom'] ?? null,
                'dateTo' => $filters['dateTo'] ?? null,
                'category' => $filters['category'] ?? null,
                'venueId' => isset($filters['venueId'])
                    ? (string) $filters['venueId']
                    : null,
                'queueEnabled' => array_key_exists('queueEnabled', $filters)
                    ? (bool) $filters['queueEnabled']
                    : null,
            ],
            'empty' => $events->isEmpty(),
            'summary' => $summary,
            'topVenues' => [
                'byEvents' => $this->top(
                    $venues,
                    'eventCount',
                    'venueName',
                ),
                'byTickets' => $this->top(
                    $venues,
                    'ticketsSold',
                    'venueName',
                ),
                'byRevenue' => $this->top(
                    $venues,
                    'revenue',
                    'venueName',
                ),
            ],
            'topCategories' => $this->top(
                $soldCategories,
                'ticketsSold',
                'category',
            ),
            'venues' => $venues->map(fn (array $group) => $frontendGroup($group, 'venueName'))->all(),
            'categories' => $categories->map(fn (array $group) => $frontendGroup($group, 'category'))->all(),
            'categoryDistribution' => $soldCategories->map(
                fn (array $category) => [
                    'category' => $category['category'],
                    'ticketsSold' => $category['ticketsSold'],
                ],
            )->all(),
            'monthlySales' => $monthlySales->all(),
            'salesHeatmap' => $heatmap->all(),
            'sellOutTime' => $sellOut,
            'queueConversion' => $conversion,
            'definitions' => [
                'timezone' => 'America/Lima',
                'dateRangeAppliesTo' => 'EVENT_START',
                'salesGroupedBy' => 'TICKET_CREATED_AT',
                'revenueIncludesServiceFees' => false,
                'occupancyMethod' => 'MEAN_OF_EVENT_PERCENTAGES',
                'averageTicketPriceMethod' => 'REVENUE_DIVIDED_BY_TICKETS',
                'weekdayConvention' => 'ISO_8601_MONDAY_1_SUNDAY_7',
                'missingSalesPeriods' => 'OMITTED',
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function sellOutTime(Collection $events): array
    {
        $now = CarbonImmutable::now('UTC');

        $durations = $events
            ->filter(fn (array $event) => $event['publishedAt'] !== null
                && $event['firstSoldOutAt'] !== null
            )
            ->map(function (array $event) use ($now) {
                $published = CarbonImmutable::parse(
                    $event['publishedAt'],
                    'UTC',
                );

                $soldOut = CarbonImmutable::parse(
                    $event['firstSoldOutAt'],
                    'UTC',
                );

                if (
                    $soldOut->lessThan($published)
                    || $soldOut->greaterThan($now)
                ) {
                    return null;
                }

                return $soldOut->getTimestamp()
                    - $published->getTimestamp();
            })
            ->filter(fn ($seconds) => $seconds !== null)
            ->values();

        return [
            'available' => $durations->isNotEmpty(),
            'averageHours' => $durations->isEmpty()
                ? null
                : round((float) $durations->avg() / 3600, 2),
            'sampleSize' => $durations->count(),
            'publishedEventCount' => $events->count(),
            'eventsWithoutMeasurement' => $events->count()
                - $durations->count(),
            'reason' => $durations->isEmpty()
                ? 'No hay eventos con una medición válida de primer agotamiento.'
                : null,
        ];
    }

    private function summarize(Collection $events): array
    {
        $sales = $this->summarizeSales($events);

        return array_merge([
            'eventCount' => $events->count(),
            'capacity' => (int) $events->sum('capacity'),
        ], $sales, [
            'averageOccupancyPercentage' => $events->isEmpty()
                ? null
                : round(
                    (float) $events->avg('occupancyPercentage'),
                    2,
                ),
            'averageTicketPrice' => $sales['ticketsSold'] > 0
                ? round(
                    $events->sum('revenueCents')
                        / $sales['ticketsSold']
                        / 100,
                    2,
                )
                : null,
        ]);
    }

    private function summarizeSales(Collection $rows): array
    {
        return [
            'ticketsSold' => (int) $rows->sum('ticketsSold'),
            'revenue' => $rows->sum('revenueCents') / 100,
            'serviceFees' => $rows->sum('serviceFeesCents') / 100,
        ];
    }

    private function top(
        Collection $rows,
        string $metric,
        string $label,
    ): array {
        return $rows
            ->filter(fn (array $row) => $row[$metric] > 0)
            ->sort(function (array $a, array $b) use ($metric, $label) {
                return ($b[$metric] <=> $a[$metric])
                    ?: strcmp($a[$label], $b[$label])
                    ?: strcmp(
                        (string) ($a['venueId'] ?? ''),
                        (string) ($b['venueId'] ?? ''),
                    );
            })
            ->take(5)
            ->values()
            ->all();
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}

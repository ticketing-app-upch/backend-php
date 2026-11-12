<?php

namespace App\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarketDashboardQuery
{
    public function events(array $filters): Collection
    {
        $capacities = DB::table('event_zones')
            ->select('event_id')
            ->selectRaw('SUM(capacity) AS capacity')
            ->groupBy('event_id');

        $sales = $this->validTickets()
            ->select('z.event_id')
            ->selectRaw('SUM(t.quantity) AS tickets_sold')
            ->selectRaw(
                'SUM(t.total_price - t.service_fee) AS revenue'
            )
            ->selectRaw('SUM(t.service_fee) AS service_fees')
            ->groupBy('z.event_id');

        return $this->publishedEvents($filters)
            ->leftJoinSub(
                $capacities,
                'capacities',
                'capacities.event_id',
                '=',
                'e.id',
            )
            ->leftJoinSub(
                $sales,
                'sales',
                'sales.event_id',
                '=',
                'e.id',
            )
            ->select([
                'e.id',
                'e.venue_id',
                'e.venue_name',
                'e.category',
                'e.queue_enabled',
                'e.published_at',
                'e.first_sold_out_at',
            ])
            ->selectRaw('COALESCE(capacities.capacity, 0) AS capacity')
            ->selectRaw('COALESCE(sales.tickets_sold, 0) AS tickets_sold')
            ->selectRaw('COALESCE(sales.revenue, 0) AS revenue')
            ->selectRaw('COALESCE(sales.service_fees, 0) AS service_fees')
            ->orderBy('e.id')
            ->get();
    }

    public function salesByDay(array $filters): Collection
    {
        $day = $this->saleDayExpression();

        $events = $this->publishedEvents($filters)
            ->select('e.id', 'e.category');

        return $this->validTickets()
            ->joinSub(
                $events,
                'market_events',
                'market_events.id',
                '=',
                'z.event_id',
            )
            ->select('market_events.category')
            ->selectRaw("$day AS sale_day")
            ->selectRaw('SUM(t.quantity) AS tickets_sold')
            ->selectRaw(
                'SUM(t.total_price - t.service_fee) AS revenue'
            )
            ->selectRaw('SUM(t.service_fee) AS service_fees')
            ->groupBy('market_events.category')
            ->groupByRaw($day)
            ->orderBy('sale_day')
            ->orderBy('market_events.category')
            ->get();
    }

    private function publishedEvents(array $filters): Builder
    {
        $query = DB::table('events as e')
            ->where('e.status', 'PUBLISHED');

        $timezone = config('ticketing.venue_timezone', 'America/Lima');

        if (isset($filters['dateFrom'])) {
            $from = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $filters['dateFrom'],
                $timezone,
            );

            $query->where('e.starts_at', '>=', $from->utc());
        }

        if (isset($filters['dateTo'])) {
            $until = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $filters['dateTo'],
                $timezone,
            )->addDay();

            $query->where('e.starts_at', '<', $until->utc());
        }

        if (isset($filters['category'])) {
            $query->whereRaw(
                'LOWER(e.category) = ?',
                [mb_strtolower($filters['category'])],
            );
        }

        if (isset($filters['venueId'])) {
            $query->where('e.venue_id', $filters['venueId']);
        }

        if (array_key_exists('queueEnabled', $filters)) {
            $query->where(
                'e.queue_enabled',
                (bool) $filters['queueEnabled'],
            );
        }

        return $query;
    }

    private function validTickets(): Builder
    {
        return DB::table('tickets as t')
            ->join('event_zones as z', 'z.id', '=', 't.event_zone_id')
            ->leftJoin(
                'ticket_orders as o',
                'o.id',
                '=',
                't.ticket_order_id',
            )
            ->where(function (Builder $query) {
                $query
                    ->whereNull('t.ticket_order_id')
                    ->orWhere('o.status', 'CONFIRMADA');
            });
    }

    private function saleDayExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "date(t.created_at, '-5 hours')",
            'mysql', 'mariadb' => "DATE(CONVERT_TZ(t.created_at, '+00:00', '-05:00'))",
            'pgsql' => "CAST((t.created_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Lima' AS DATE)",
            default => throw new \LogicException(
                'Motor de base de datos no soportado para el dashboard.',
            ),
        };
    }
}

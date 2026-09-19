<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $zones = $this->zones->map(function ($zone) {
            $ticketsSold = (int) ($zone->tickets_sold ?? 0);
            $revenue = round((float) ($zone->revenue ?? 0), 2);
            $occupancyPercentage = $zone->capacity > 0
                ? round(($ticketsSold / $zone->capacity) * 100, 2)
                : 0.0;

            return [
                'id' => (string) $zone->id,
                'name' => $zone->name,
                'capacity' => $zone->capacity,
                'availableCapacity' => $zone->available_capacity,
                'ticketsSold' => $ticketsSold,
                'revenue' => $revenue,
                'occupancyPercentage' => $occupancyPercentage,
            ];
        });

        $totalCapacity = (int) $zones->sum('capacity');
        $availableCapacity = (int) $zones->sum('availableCapacity');
        $ticketsSold = (int) $zones->sum('ticketsSold');

        return [
            'event' => [
                'id' => (string) $this->id,
                'title' => $this->title,
                'venueCapacity' => $this->venue_capacity,
            ],
            'summary' => [
                'totalRevenue' => round((float) $zones->sum('revenue'), 2),
                'ticketsSold' => $ticketsSold,
                'totalCapacity' => $totalCapacity,
                'availableCapacity' => $availableCapacity,
                'occupancyPercentage' => $totalCapacity > 0
                    ? round(($ticketsSold / $totalCapacity) * 100, 2)
                    : 0.0,
            ],
            'zones' => $zones->values()->all(),
        ];
    }
}

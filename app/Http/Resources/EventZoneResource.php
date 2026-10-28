<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'venueZoneId' => $this->venue_zone_id === null ? null : (string) $this->venue_zone_id,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'availableCapacity' => $this->available_capacity,
            'basePrice' => (float) $this->base_price,
            'price' => (float) $this->base_price,
            'sold' => $this->capacity - $this->available_capacity,
        ];
    }
}

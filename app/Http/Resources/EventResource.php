<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'venueName' => $this->venue_name,
            'venueAddress' => $this->venue_address,
            'venueCapacity' => $this->venue_capacity,
            'startsAt' => $this->starts_at->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
            'status' => $this->status,
            'zones' => EventZoneResource::collection($this->whenLoaded('zones')),
        ];
    }
}

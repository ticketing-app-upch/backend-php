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
            'venueId' => $this->venue_id === null ? null : (string) $this->venue_id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'venueName' => $this->venue_name,
            'venueAddress' => $this->venue_address,
            'venueCapacity' => $this->venue_capacity,
            'startsAt' => $this->starts_at->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
            'status' => $this->status,
            'bannerUrl' => $this->banner_exists ? rtrim(config('app.url'), '/').'/api/events/'.$this->id.'/banner' : null,
            'shareUrl' => rtrim(config('ticketing.frontend_url') ?: config('app.url'), '/').'/attendee/event/'.$this->id,
            'isFavorite' => (bool) ($this->is_favorite ?? false),
            'venue' => new VenueResource($this->whenLoaded('venue')),
            'zones' => EventZoneResource::collection($this->whenLoaded('zones')),
        ];
    }
}

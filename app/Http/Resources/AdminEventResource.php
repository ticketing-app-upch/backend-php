<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $baseUrl = rtrim(config('app.url'), '/')
            .'/api/v1/admin/eventos/'.$this->id;

        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'status' => $this->status,
            'startsAt' => $this->starts_at->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
            'queueEnabled' => $this->queue_enabled,
            'organizer' => [
                'id' => (string) $this->organizer->id,
                'fullName' => $this->organizer->full_name,
            ],
            'venueName' => $this->venue_name,
            'venueAddress' => $this->venue_address,
            'venueCapacity' => $this->venue_capacity,
            'venue' => new VenueResource($this->whenLoaded('venue')),
            'zones' => EventZoneResource::collection(
                $this->whenLoaded('zones')
            ),
            'bannerUrl' => $this->banner_exists
                ? $baseUrl.'/banner'
                : null,
            'secondaryBannerUrl' => $this->secondary_banner_exists
                ? $baseUrl.'/secondary-banner'
                : null,
        ];
    }
}

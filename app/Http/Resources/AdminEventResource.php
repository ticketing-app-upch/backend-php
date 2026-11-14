<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class AdminEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $origin = rtrim(config('app.url'), '/');
        $baseUrl = $origin.'/api/v1/admin/eventos/'.$this->id;
        $list = $request->is('api/v1/admin/eventos');
        $imageUrl = fn (string $image) => $list
            ? $origin.URL::temporarySignedRoute('admin.event-review.image', now()->addMinutes(5), ['id' => $this->id, 'image' => $image], absolute: false)
            : $baseUrl.'/'.$image;

        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'name' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'status' => $this->status,
            'startsAt' => $this->starts_at->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
            'queueEnabled' => $this->queue_enabled,
            'organizerId' => (string) $this->organizer_id,
            'city' => $this->city,
            'organizer' => [
                'id' => (string) $this->organizer->id,
                'fullName' => $this->organizer->full_name,
            ],
            'venueName' => $this->venue_name,
            'venueAddress' => $this->venue_address,
            'venueCapacity' => $this->venue_capacity,
            'venue' => $list
                ? $this->venue_name
                : new VenueResource($this->whenLoaded('venue')),
            'venueDetails' => new VenueResource($this->whenLoaded('venue')),
            'zones' => EventZoneResource::collection(
                $this->whenLoaded('zones')
            ),
            'bannerUrl' => $this->banner_exists
                ? $imageUrl('banner')
                : null,
            'secondaryBannerUrl' => $this->secondary_banner_exists
                ? $imageUrl('secondary-banner')
                : null,
        ];
    }
}

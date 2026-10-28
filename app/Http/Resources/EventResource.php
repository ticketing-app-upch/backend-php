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
        $bannerUrl = $this->banner_exists ? rtrim(config('app.url'), '/').'/api/events/'.$this->id.'/banner' : null;
        $legacy = $request->query('contract') === 'legacy'
            || $request->is('api/v1/eventos/*', 'api/v1/favoritos')
            || ($request->isMethod('POST') && ! $request->exists('name'));
        $result = [
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
            'bannerUrl' => $bannerUrl,
            'shareUrl' => rtrim(config('ticketing.frontend_url') ?: config('app.url'), '/').'/attendee/event/'.$this->id,
            'isFavorite' => (bool) ($this->is_favorite ?? false),
            'venue' => new VenueResource($this->whenLoaded('venue')),
            'zones' => EventZoneResource::collection($this->whenLoaded('zones')),
        ];

        if ($legacy) {
            return $result;
        }

        $available = $this->resource->relationLoaded('zones') ? $this->zones->sum('available_capacity') : 0;
        $status = match ($this->status) {
            'PUBLISHED' => $this->starts_at->isPast() ? 'FINALIZADO' : ($available > 0 ? 'PUBLICADO' : 'AGOTADO'),
            'DRAFT' => 'BORRADOR',
            default => 'FINALIZADO',
        };

        return array_replace($result, [
            'name' => $this->title, 'status' => $status, 'backendStatus' => $this->status,
            'venue' => $this->venue_name, 'venueDetails' => $result['venue'],
            'city' => $this->city ?: ($this->resource->relationLoaded('venue') ? $this->venue?->city : ''),
            'publishedAt' => ($this->published_at ?? $this->created_at)?->toIso8601String(),
            'organizerId' => (string) $this->organizer_id, 'maxPerOrder' => $this->max_per_order ?? 6,
            'imageUrl' => $this->image_url ?: ($bannerUrl ?? ''),
            'secondaryBannerUrl' => $this->secondary_banner_exists
                ? rtrim(config('app.url'), '/').'/api/events/'.$this->id.'/secondary-banner' : null,
        ]);
    }
}

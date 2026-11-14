<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $legacy = $request->query('contract') === 'legacy'
            || $request->is('api/v1/favoritos')
            || ($request->is('api/v1/eventos/*') && ! $request->is('api/v1/eventos/*/habilitar-compras'))
            || ($request->isMethod('POST') && ! $request->exists('name'));
        $user = $request->user('api');
        $isOwner = $user !== null
            && $user->role->name === 'ORGANIZER'
            && (string) $user->id === (string) $this->organizer_id;
        $canViewReview = $isOwner
            || ($user !== null && $user->role->name === 'ADMIN');
        $bannerBase = rtrim(config('app.url'), '/')
            .($this->status === Event::STATUS_PUBLISHED
                ? '/api/events/'
                : ($isOwner
                    ? '/api/v1/eventos/'
                    : '/api/v1/admin/eventos/'))
            .$this->id;

        $bannerUrl = $this->banner_exists
            ? $bannerBase.'/banner'
            : null;
        $available = $this->resource->relationLoaded('zones') ? $this->zones->sum('available_capacity') : 0;
        $status = match ($this->status) {
            'PUBLISHED' => $this->starts_at->isPast()
                ? 'FINALIZADO'
                : ($available > 0 ? 'PUBLICADO' : 'AGOTADO'),
            'PENDING_APPROVAL' => $this->isReviewExpired()
                ? 'VENCIDO'
                : 'PENDIENTE_APROBACION',
            'REJECTED' => 'RECHAZADO',
            'DRAFT' => 'BORRADOR',
            default => 'FINALIZADO',
        };
        $frontendStatus = ($request->is('api/organizers/*/events') || $this->relationLoaded('banner'))
            && $this->status === Event::STATUS_PENDING_APPROVAL
            ? 'PENDIENTE'
            : $status;
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
            'secondaryBannerUrl' => $this->secondary_banner_exists
                ? $bannerBase.'/secondary-banner'
                : null,
            'shareUrl' => rtrim(config('ticketing.frontend_url') ?: config('app.url'), '/').'/attendee/event/'.$this->id,
            'isFavorite' => (bool) ($this->is_favorite ?? false),
            'venue' => new VenueResource($this->whenLoaded('venue')),
            'zones' => EventZoneResource::collection($this->whenLoaded('zones')),
            'queueEnabled' => $this->queue_enabled,
            'purchasesEnabled' => ! $this->queue_enabled || $this->purchases_enabled_at !== null,
            'purchasesEnabledAt' => $this->purchases_enabled_at?->toIso8601String(),
            'displayStatus' => $status,
            'rejectionReason' => $this->when($canViewReview, fn () => $this->rejection_reason),
            'reviewedAt' => $this->when($canViewReview, fn () => $this->reviewed_at?->toIso8601String()),
            'canEdit' => $this->when($canViewReview, fn () => $isOwner && $user->active && $this->status === Event::STATUS_REJECTED),
        ];

        if ($legacy) {
            return $result;
        }

        if ($isOwner && $this->status !== Event::STATUS_PUBLISHED && $this->relationLoaded('banner')) {
            $result['bannerUrl'] = $this->banner?->content ? 'data:image/jpeg;base64,'.$this->banner->content : null;
            $result['secondaryBannerUrl'] = $this->banner?->secondary_content ? 'data:image/jpeg;base64,'.$this->banner->secondary_content : null;
            $bannerUrl = $result['bannerUrl'];
        }

        return array_replace($result, [
            'name' => $this->title, 'status' => $frontendStatus, 'backendStatus' => $this->status,
            'venue' => $this->venue_name, 'venueDetails' => $result['venue'],
            'city' => $this->city ?: ($this->resource->relationLoaded('venue') ? $this->venue?->city : ''),
            'publishedAt' => ($this->published_at ?? ($this->status === Event::STATUS_PUBLISHED
                ? $this->created_at
                : null))?->toIso8601String(),
            'organizerId' => (string) $this->organizer_id, 'maxPerOrder' => $this->max_per_order ?? 6,
            'imageUrl' => $this->image_url ?: ($bannerUrl ?? ''),
        ]);
    }
}

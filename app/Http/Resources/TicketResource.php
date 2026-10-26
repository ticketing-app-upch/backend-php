<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $validationUrl = rtrim(config('ticketing.frontend_url') ?: config('app.url'), '/').'/validar/'.rawurlencode($this->code);

        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'quantity' => $this->quantity,
            'totalPrice' => (float) $this->total_price,
            'unitPrice' => (float) ($this->unit_price ?? $this->total_price / max(1, $this->quantity)),
            'validationUrl' => $validationUrl,
            'qrValue' => $validationUrl,
            'createdAt' => $this->created_at->toIso8601String(),
            'event' => [
                'id' => (string) $this->eventZone->event->id,
                'title' => $this->event_title ?? $this->eventZone->event->title,
                'venueName' => $this->venue_name ?? $this->eventZone->event->venue_name,
                'venueAddress' => $this->venue_address ?? $this->eventZone->event->venue_address,
                'startsAt' => ($this->event_starts_at ?? $this->eventZone->event->starts_at)->toIso8601String(),
            ],
            'zone' => [
                'id' => (string) $this->eventZone->id,
                'name' => $this->zone_name ?? $this->eventZone->name,
                'basePrice' => (float) ($this->unit_price ?? $this->total_price / max(1, $this->quantity)),
            ],
        ];
    }
}

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
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'quantity' => $this->quantity,
            'totalPrice' => (float) $this->total_price,
            'createdAt' => $this->created_at->toIso8601String(),
            'event' => [
                'id' => (string) $this->eventZone->event->id,
                'title' => $this->eventZone->event->title,
                'venueName' => $this->eventZone->event->venue_name,
                'startsAt' => $this->eventZone->event->starts_at->toIso8601String(),
            ],
            'zone' => [
                'id' => (string) $this->eventZone->id,
                'name' => $this->eventZone->name,
                'basePrice' => (float) $this->eventZone->base_price,
            ],
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'code' => $this->code,
            'eventId' => (string) $this->event_id, 'eventName' => $this->event_name,
            'eventStartsAt' => $this->event_starts_at->toIso8601String(), 'eventVenue' => $this->event_venue,
            'buyerId' => (string) $this->user_id, 'buyerName' => $this->buyer_name,
            'createdAt' => $this->created_at->toIso8601String(), 'status' => $this->status,
            'lines' => $this->lines,
            'tickets' => $this->tickets->map(fn ($ticket) => [
                'code' => $ticket->code, 'zoneId' => (string) $ticket->event_zone_id,
                'zoneName' => $ticket->zone_name, 'unitPrice' => (float) $ticket->unit_price,
                'serviceFee' => (float) $ticket->service_fee, 'total' => (float) $ticket->total_price,
            ]),
            'subtotal' => (float) $this->subtotal, 'fee' => (float) $this->fee, 'total' => (float) $this->total,
        ];
    }
}

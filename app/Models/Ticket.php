<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'event_zone_id', 'quantity', 'total_price', 'code',
    'event_title', 'event_starts_at', 'venue_name', 'venue_address', 'zone_name', 'unit_price',
    'ticket_order_id', 'service_fee'])]
class Ticket extends Model
{
    protected static function booted(): void
    {
        static::updating(function (Ticket $ticket) {
            if ($ticket->isDirty(['user_id', 'event_zone_id', 'quantity', 'total_price', 'code',
                'event_title', 'event_starts_at', 'venue_name', 'venue_address', 'zone_name', 'unit_price',
                'ticket_order_id', 'service_fee'])) {
                throw new \LogicException('Los datos de una entrada emitida son inmutables.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'total_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'event_starts_at' => 'datetime',
            'service_fee' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function eventZone(): BelongsTo
    {
        return $this->belongsTo(EventZone::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(TicketOrder::class, 'ticket_order_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'event_zone_id', 'quantity', 'total_price', 'code',
    'event_title', 'event_starts_at', 'venue_name', 'venue_address', 'zone_name', 'unit_price'])]
class Ticket extends Model
{
    protected static function booted(): void
    {
        static::updating(function (Ticket $ticket) {
            if ($ticket->isDirty(['user_id', 'event_zone_id', 'quantity', 'total_price', 'code',
                'event_title', 'event_starts_at', 'venue_name', 'venue_address', 'zone_name', 'unit_price'])) {
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
}

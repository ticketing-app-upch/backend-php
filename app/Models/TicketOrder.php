<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'event_id', 'code', 'buyer_name', 'event_name', 'event_starts_at',
    'event_venue', 'status', 'payment_method', 'idempotency_key', 'request_hash', 'lines', 'subtotal', 'fee', 'total'])]
class TicketOrder extends Model
{
    protected static function booted(): void
    {
        static::updating(function (TicketOrder $order) {
            if ($order->isDirty(['user_id', 'event_id', 'code', 'buyer_name', 'event_name', 'event_starts_at',
                'event_venue', 'payment_method', 'idempotency_key', 'request_hash', 'lines', 'subtotal', 'fee', 'total'])) {
                throw new \LogicException('Los datos de una compra emitida son inmutables.');
            }
        });
    }

    protected function casts(): array
    {
        return ['event_starts_at' => 'datetime', 'lines' => 'array',
            'subtotal' => 'decimal:2', 'fee' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class)->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}

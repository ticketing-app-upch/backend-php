<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'name',
    'capacity',
    'available_capacity',
    'base_price',
])]
class EventZone extends Model
{
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'available_capacity' => 'integer',
            'base_price' => 'decimal:2',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}

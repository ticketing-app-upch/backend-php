<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organizer_id',
    'venue_id',
    'title',
    'description',
    'category',
    'venue_name',
    'venue_address',
    'venue_capacity',
    'starts_at',
    'ends_at',
    'status',
])]
class Event extends Model
{
    protected function casts(): array
    {
        return [
            'venue_capacity' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function zones(): HasMany
    {
        return $this->hasMany(EventZone::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function banner(): HasOne
    {
        return $this->hasOne(EventBanner::class);
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }
}

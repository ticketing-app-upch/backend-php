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
    'city',
    'image_url',
    'published_at',
    'max_per_order',
    'queue_enabled',
    'rejection_reason',
    'reviewed_at',
    'reviewed_by',
])]
class Event extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const STATUS_REJECTED = 'REJECTED';

    protected function casts(): array
    {
        return [
            'venue_capacity' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'max_per_order' => 'integer',
            'queue_enabled' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function isReviewExpired(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL
            && $this->starts_at !== null
            && $this->starts_at->lessThanOrEqualTo(now());
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
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

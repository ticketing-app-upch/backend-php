<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'address', 'capacity', 'slug', 'city', 'map_image'])]
class Venue extends Model
{
    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    public function zones(): HasMany
    {
        return $this->hasMany(VenueZone::class)->orderBy('id');
    }
}

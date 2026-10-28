<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['venue_id', 'name', 'capacity', 'map_shape'])]
class VenueZone extends Model
{
    protected function casts(): array
    {
        return ['capacity' => 'integer', 'map_shape' => 'array'];
    }
}

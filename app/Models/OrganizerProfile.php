<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'org_type',
    'display_name',
    'tax_id',
    'legal_name',
    'rep_name',
    'phone',
    'country',
    'city',
    'website',
])]
class OrganizerProfile extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

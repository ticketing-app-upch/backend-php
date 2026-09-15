<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'country',
    'city',
    'district',
    'has_peruvian_nationality',
    'doc_type',
    'doc_number',
    'gender',
    'phone_code',
    'phone',
])]
class ClientProfile extends Model
{
    protected function casts(): array
    {
        return [
            'has_peruvian_nationality' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

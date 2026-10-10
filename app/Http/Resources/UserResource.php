<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'fullName' => $this->full_name,
            'email' => $this->email,
            'role' => $this->role->name,

            'profile' => $this->when($this->role->name === 'CLIENT', fn () => [
                'docNumber' => $this->clientProfile->doc_number,
            ]),

            'organizer' => $this->when($this->role->name === 'ORGANIZER', fn () => [
                'taxId' => $this->organizerProfile->tax_id,
            ]),
        ];
    }
}

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

            'marketingOptIn' => $this->when($this->marketing_opt_in !== null, $this->marketing_opt_in),
            'profile' => $this->when($this->role->name === 'CLIENT', fn () => array_merge($this->clientProfile->details ?? [], [
                'docNumber' => $this->clientProfile->doc_number,
            ])),

            'organizer' => $this->when($this->role->name === 'ORGANIZER', fn () => array_merge($this->organizerProfile->details ?? [], [
                'taxId' => $this->organizerProfile->tax_id,
            ])),
        ];
    }
}

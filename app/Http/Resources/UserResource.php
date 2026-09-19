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
            'marketingOptIn' => (bool) $this->marketing_opt_in,

            'profile' => $this->when($this->role->name === 'CLIENT', fn () => [
                'country' => $this->clientProfile->country,
                'city' => $this->clientProfile->city,
                'district' => $this->clientProfile->district,
                'hasPeruvianNationality' => (bool) $this->clientProfile->has_peruvian_nationality,
                'docType' => $this->clientProfile->doc_type,
                'docNumber' => $this->clientProfile->doc_number,
                'gender' => $this->clientProfile->gender,
                'phoneCode' => $this->clientProfile->phone_code,
                'phone' => $this->clientProfile->phone,
            ]),

            'organizer' => $this->when($this->role->name === 'ORGANIZER', fn () => [
                'orgType' => $this->organizerProfile->org_type,
                'displayName' => $this->organizerProfile->display_name,
                'taxId' => $this->organizerProfile->tax_id,
                'legalName' => $this->organizerProfile->legal_name,
                'repName' => $this->organizerProfile->rep_name,
                'phone' => $this->organizerProfile->phone,
                'country' => $this->organizerProfile->country,
                'city' => $this->organizerProfile->city,
                'website' => $this->organizerProfile->website,
                'verificationStatus' => $this->organizerProfile->verification_status,
            ]),
        ];
    }
}

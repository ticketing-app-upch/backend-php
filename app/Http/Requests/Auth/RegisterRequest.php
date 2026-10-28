<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fullName' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'regex:/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/'],
            'role' => ['required', 'string', 'in:CLIENT,ORGANIZER'],
            'acceptedTerms' => ['required', 'boolean:strict', 'accepted'],
            'marketingOptIn' => ['sometimes', 'boolean:strict'],

            'profile' => ['exclude_unless:role,CLIENT', 'required', 'array'],
            'profile.docType' => ['exclude_unless:role,CLIENT', 'sometimes', 'in:DNI'],
            'profile.docNumber' => ['exclude_unless:role,CLIENT', 'required', 'string', 'regex:/\A[0-9]{8}\z/'],
            'profile.country' => ['sometimes', 'string', 'max:100'],
            'profile.city' => ['sometimes', 'string', 'max:255'],
            'profile.district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.hasPeruvianNationality' => ['sometimes', 'boolean:strict'],
            'profile.gender' => ['sometimes', 'in:F,M'],
            'profile.phoneCode' => ['sometimes', 'string', 'max:8'],
            'profile.phone' => ['sometimes', 'string', 'max:30'],

            'organizer' => ['exclude_unless:role,ORGANIZER', 'required', 'array'],
            'organizer.taxId' => ['exclude_unless:role,ORGANIZER', 'required', 'string', 'regex:/\A(10|15|17|20)[0-9]{9}\z/'],
            'organizer.orgType' => ['sometimes', 'in:PERSONA,EMPRESA'],
            'organizer.displayName' => ['sometimes', 'string', 'max:255'],
            'organizer.legalName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'organizer.repName' => ['sometimes', 'string', 'max:255'],
            'organizer.phone' => ['sometimes', 'string', 'max:30'],
            'organizer.country' => ['sometimes', 'string', 'max:100'],
            'organizer.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'organizer.website' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
        ];
    }
}

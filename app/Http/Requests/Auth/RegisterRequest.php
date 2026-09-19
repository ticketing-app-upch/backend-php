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
            'fullName' => ['required', 'string', 'min:2'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'regex:/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/'],
            'role' => ['required', 'string', 'in:CLIENT,ORGANIZER'],
            'acceptedTerms' => ['required', function ($attribute, $value, $fail) {
                if ($value !== true) {
                    $fail('Debes aceptar los términos y condiciones.');
                }
            }],
            'marketingOptIn' => ['sometimes', 'boolean'],

            'profile' => ['required_if:role,CLIENT', 'array'],
            'profile.country' => ['required_if:role,CLIENT', 'string', 'size:2'],
            'profile.city' => ['required_if:profile.country,PE', 'nullable', 'string'],
            'profile.district' => ['nullable', 'string'],
            'profile.hasPeruvianNationality' => ['required_if:role,CLIENT', 'boolean'],
            'profile.docType' => [
                'required_if:role,CLIENT',
                'in:DNI,CE,PASAPORTE',
                function ($attribute, $value, $fail) {
                    $isPeru = $this->input('profile.country') === 'PE';
                    $hasPeruvianNationality = $this->input('profile.hasPeruvianNationality') === true;

                    if ($value === 'DNI' && ! $isPeru && ! $hasPeruvianNationality) {
                        $fail('El DNI solo aplica a personas con nacionalidad peruana.');
                    }
                },
            ],
            'profile.docNumber' => [
                'required_if:role,CLIENT',
                'string',
                function ($attribute, $value, $fail) {
                    $pattern = match ($this->input('profile.docType')) {
                        'DNI' => '/^\d{8}$/',
                        'CE' => '/^\d{9,12}$/',
                        'PASAPORTE' => '/^[A-Za-z0-9]{6,12}$/',
                        default => null,
                    };

                    if ($pattern !== null && ! preg_match($pattern, (string) $value)) {
                        $fail('El número de documento no tiene el formato esperado para el tipo seleccionado.');
                    }
                },
            ],
            'profile.gender' => ['required_if:role,CLIENT', 'in:F,M'],
            'profile.phoneCode' => ['required_if:role,CLIENT', 'regex:/^\+\d{1,4}$/'],
            'profile.phone' => ['required_if:role,CLIENT', 'regex:/^\d{6,17}$/'],

            'organizer' => ['required_if:role,ORGANIZER', 'array'],
            'organizer.orgType' => ['required_if:role,ORGANIZER', 'in:PERSONA,EMPRESA'],
            'organizer.displayName' => ['required_if:role,ORGANIZER', 'string', 'min:2'],
            'organizer.taxId' => [
                'required_if:role,ORGANIZER',
                'string',
                function ($attribute, $value, $fail) {
                    $pattern = match ($this->input('organizer.orgType')) {
                        'PERSONA' => '/^\d{8}$/',
                        'EMPRESA' => '/^(10|15|17|20)\d{9}$/',
                        default => null,
                    };

                    if ($pattern !== null && ! preg_match($pattern, (string) $value)) {
                        $fail('El RUC/DNI no tiene el formato esperado para el tipo de organizador seleccionado.');
                    }
                },
            ],
            'organizer.legalName' => ['required_if:organizer.orgType,EMPRESA', 'nullable', 'string'],
            'organizer.repName' => ['required_if:role,ORGANIZER', 'string'],
            'organizer.phone' => ['required_if:role,ORGANIZER', 'regex:/^\d{6,17}$/'],
            'organizer.country' => ['required_if:role,ORGANIZER', 'string', 'size:2'],
            'organizer.city' => ['required_if:organizer.country,PE', 'nullable', 'string'],
            'organizer.website' => ['nullable', 'string', 'regex:/^(https?:\/\/)?([\w-]+\.)+[\w-]{2,}(\/\S*)?$/i'],
        ];
    }
}

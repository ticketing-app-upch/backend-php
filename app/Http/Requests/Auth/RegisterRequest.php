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

            'profile' => ['exclude_unless:role,CLIENT', 'required', 'array'],
            'profile.docType' => ['exclude_unless:role,CLIENT', 'sometimes', 'in:DNI'],
            'profile.docNumber' => ['exclude_unless:role,CLIENT', 'required', 'string', 'regex:/\A[0-9]{8}\z/'],

            'organizer' => ['exclude_unless:role,ORGANIZER', 'required', 'array'],
            'organizer.taxId' => ['exclude_unless:role,ORGANIZER', 'required', 'string', 'regex:/\A(10|15|17|20)[0-9]{9}\z/'],
        ];
    }
}

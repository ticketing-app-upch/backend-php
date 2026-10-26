<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IndexUserRequest extends FormRequest
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
            'role' => ['sometimes', 'string', 'in:CLIENT,ORGANIZER'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}

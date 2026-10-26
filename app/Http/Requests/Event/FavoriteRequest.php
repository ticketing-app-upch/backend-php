<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class FavoriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['eventId' => ['required', 'integer', 'min:1']];
    }
}

<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class PriceQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['id_zona' => ['required', 'integer', 'min:1']];
    }
}

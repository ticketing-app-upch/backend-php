<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    Event::STATUS_PUBLISHED,
                    Event::STATUS_REJECTED,
                ]),
            ],
            'rejectionReason' => [
                'required_if:status,'.Event::STATUS_REJECTED,
                'prohibited_unless:status,'.Event::STATUS_REJECTED,
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}

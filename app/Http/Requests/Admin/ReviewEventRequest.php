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

    protected function prepareForValidation(): void
    {
        if ($this->has('status')) {
            return;
        }

        $status = match ($this->input('estado')) {
            'publicado' => Event::STATUS_PUBLISHED,
            'rechazado' => Event::STATUS_REJECTED,
            default => null,
        };
        if ($status === null) {
            return;
        }

        $this->merge(['status' => $status]);
        if ($status === Event::STATUS_REJECTED && ! $this->has('rejectionReason')) {
            $this->merge(['rejectionReason' => $this->input('motivo')]);
        }
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

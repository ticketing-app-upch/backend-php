<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dateFrom' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
            ],
            'dateTo' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                Rule::when(
                    $this->filled('dateFrom'),
                    ['after_or_equal:dateFrom'],
                ),
            ],
            'category' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],
            'venueId' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'exists:venues,id',
            ],
            'queueEnabled' => [
                'sometimes',
                'required',
                'boolean',
            ],
        ];
    }
}

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

    protected function prepareForValidation(): void
    {
        $aliases = [];
        foreach (['from' => 'dateFrom', 'to' => 'dateTo'] as $alias => $field) {
            if ($this->has($alias) && ! $this->has($field)) {
                $aliases[$field] = $this->input($alias);
            }
        }
        $queue = $this->input('queue');
        if (! $this->has('queueEnabled') && in_array($queue, ['with', 'without'], true)) {
            $aliases['queueEnabled'] = $queue === 'with';
        }
        $this->merge($aliases);
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
            'queue' => ['sometimes', 'required', Rule::in(['all', 'with', 'without'])],
            'venue' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}

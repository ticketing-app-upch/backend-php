<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'venueId' => ['required', 'integer', 'exists:venues,id'],
            'venueName' => ['prohibited'],
            'venueAddress' => ['prohibited'],
            'venueCapacity' => ['prohibited'],
            'banner' => ['required', 'file', 'image', 'mimes:jpg,jpeg', 'mimetypes:image/jpeg',
                'max:2048', 'dimensions:min_width=1920,min_height=480,ratio=4/1'],
            'startsAt' => ['required', 'date', 'after:now'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
            'zones' => ['required', 'array', 'min:1'],
            'zones.*.venueZoneId' => ['required', 'integer', 'distinct'],
            'zones.*.name' => ['prohibited'],
            'zones.*.capacity' => ['prohibited'],
            'zones.*.basePrice' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999.99'],
        ];
    }
}

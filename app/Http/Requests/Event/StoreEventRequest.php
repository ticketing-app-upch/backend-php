<?php

namespace App\Http\Requests\Event;

use App\Rules\Base64Jpeg;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->usesFrontendContract()) {
            return [
                'id' => ['nullable', 'string', 'max:64'],
                'name' => ['required', 'string', 'min:4', 'max:255'],
                'description' => ['required', 'string', 'min:20'],
                'category' => ['required', 'in:CONCIERTO,TEATRO,DEPORTE,CONFERENCIA,FESTIVAL'],
                'status' => ['required', 'in:PUBLICADO'],
                'venue' => ['required', 'string', 'exists:venues,name'],
                'city' => ['required', 'string', 'max:255'],
                'venueCapacity' => ['required', 'integer', 'min:1'],
                'organizerId' => ['sometimes', 'string', 'max:64'],
                'maxPerOrder' => ['required', 'integer', 'min:1', 'max:6'],
                'startsAt' => ['required', 'date', 'after:now'],
                'publishedAt' => ['sometimes', 'date'],
                'imageUrl' => ['nullable', 'string', 'max:2048', 'regex:~^(https?://|/)~'],
                'bannerUrl' => ['required', new Base64Jpeg],
                'secondaryBannerUrl' => ['required', 'different:bannerUrl', new Base64Jpeg],
                'zones' => ['required', 'array', 'min:1'],
                'zones.*.id' => ['required', 'string', 'distinct', 'max:128'],
                'zones.*.name' => ['required', 'string', 'distinct', 'max:255'],
                'zones.*.capacity' => ['required', 'integer', 'min:1'],
                'zones.*.sold' => ['required', 'integer', 'in:0'],
                'zones.*.openingSold' => ['prohibited'],
                'zones.*.openingRevenue' => ['prohibited'],
                'zones.*.price' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999.99'],
            ];
        }

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

    public function usesFrontendContract(): bool
    {
        return $this->exists('name');
    }
}

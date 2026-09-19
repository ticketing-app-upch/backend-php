<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreEventRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'venueName' => ['required', 'string', 'max:255'],
            'venueAddress' => ['required', 'string', 'max:255'],
            'venueCapacity' => ['required', 'integer', 'min:1'],
            'startsAt' => ['required', 'date', 'after:now'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],

            'zones' => ['required', 'array', 'min:1'],
            'zones.*.name' => ['required', 'string', 'max:255'],
            'zones.*.capacity' => ['required', 'integer', 'min:1'],
            'zones.*.basePrice' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $zones = $this->input('zones');

            if (! is_array($zones)) {
                return;
            }

            $seenNames = [];

            foreach ($zones as $zone) {
                $name = mb_strtolower(trim((string) ($zone['name'] ?? '')));

                if ($name === '') {
                    continue;
                }

                if (in_array($name, $seenNames, true)) {
                    $validator->errors()->add('zones', 'Los nombres de zona no pueden repetirse dentro del mismo evento.');
                    break;
                }

                $seenNames[] = $name;
            }

            $venueCapacity = $this->input('venueCapacity');
            $totalZoneCapacity = array_sum(array_map(
                fn ($zone) => (int) ($zone['capacity'] ?? 0),
                $zones
            ));

            if (is_numeric($venueCapacity) && $totalZoneCapacity > (int) $venueCapacity) {
                $validator->errors()->add('venueCapacity', 'La suma de la capacidad de las zonas no puede superar la capacidad del recinto.');
            }
        });
    }
}

<?php

namespace App\Http\Requests\Event;

use App\Models\EventZone;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseTicketRequest extends FormRequest
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
            'zoneId' => ['required', 'integer', 'exists:event_zones,id', function ($attribute, $value, $fail) {
                $zone = EventZone::find($value);

                if (! $zone || $zone->event_id !== (int) $this->route('id')) {
                    $fail('La zona no pertenece a este evento.');
                }
            }],
            'quantity' => ['required', 'integer', 'min:1', 'max:10', function ($attribute, $value, $fail) {
                $zoneId = $this->input('zoneId');

                if (! $zoneId) {
                    return;
                }

                $zone = EventZone::find($zoneId);

                if ($zone && $value > $zone->available_capacity) {
                    $fail('No hay suficiente capacidad disponible en esa zona.');
                }
            }],
        ];
    }
}

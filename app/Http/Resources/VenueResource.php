<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'name' => $this->name,
            'address' => $this->address, 'capacity' => $this->capacity,
            'map' => ['viewBox' => '0 0 1000 620',
                'stage' => ['x' => 300, 'y' => 30, 'width' => 400, 'height' => 70, 'label' => 'Escenario']],
            'zones' => $this->zones->map(fn ($zone) => [
                'id' => (string) $zone->id, 'name' => $zone->name,
                'capacity' => $zone->capacity, 'shape' => $zone->map_shape,
            ]),
        ];
    }
}

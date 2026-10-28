<?php

namespace Database\Seeders;

use App\Models\Venue;
use Illuminate\Database\Seeder;

class VenueSeeder extends Seeder
{
    public function run(): void
    {
        // Catálogo de maqueta de la rama demo3; conserva los IDs de filas existentes.
        $catalog = json_decode(file_get_contents(database_path('data/frontend-venues.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($catalog as $item) {
            $venue = Venue::updateOrCreate(['name' => $item['name']], [
                'slug' => $item['id'], 'address' => $item['address'], 'city' => $item['city'],
                'map_image' => $item['map'], 'capacity' => array_sum(array_column($item['zones'], 'capacity')),
            ]);
            foreach ($item['zones'] as $index => $zone) {
                $venue->zones()->updateOrCreate(['name' => $zone['name']], [
                    'capacity' => $zone['capacity'],
                    'map_shape' => ['type' => 'rect', 'x' => 60, 'y' => 140 + $index * 40,
                        'width' => 880, 'height' => 32,
                        'color' => ['#be185d', '#6d28d9', '#0369a1'][$index % 3]],
                ]);
            }
        }
    }
}

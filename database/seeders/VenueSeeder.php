<?php

namespace Database\Seeders;

use App\Models\Venue;
use Illuminate\Database\Seeder;

class VenueSeeder extends Seeder
{
    public function run(): void
    {
        // Datos demostrativos; no representan aforos de recintos reales.
        foreach ([
            ['Auditorio AlpaTeck', 'Recinto demostrativo Lima', [100, 200, 300]],
            ['Arena AlpaTeck', 'Recinto demostrativo Arequipa', [300, 600, 900]],
            ['Teatro AlpaTeck', 'Recinto demostrativo Cusco', [75, 150, 225]],
        ] as [$name, $address, $capacities]) {
            $venue = Venue::firstOrCreate(['name' => $name], [
                'address' => $address, 'capacity' => array_sum($capacities),
            ]);
            foreach (['VIP', 'Preferencial', 'General'] as $index => $zoneName) {
                $venue->zones()->firstOrCreate(['name' => $zoneName], [
                    'capacity' => $capacities[$index],
                    'map_shape' => ['type' => 'rect', 'x' => 60, 'y' => 140 + $index * 140,
                        'width' => 880, 'height' => 120,
                        'color' => ['#be185d', '#6d28d9', '#0369a1'][$index]],
                ]);
            }
        }
    }
}

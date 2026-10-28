<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('event_title')->nullable();
            $table->dateTime('event_starts_at')->nullable();
            $table->string('venue_name')->nullable();
            $table->string('venue_address')->nullable();
            $table->string('zone_name')->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
        });

        // Conserva códigos/IDs anteriores y el importe vendido al individualizar.
        DB::table('tickets')
            ->join('event_zones', 'event_zones.id', '=', 'tickets.event_zone_id')
            ->join('events', 'events.id', '=', 'event_zones.event_id')
            ->whereNull('tickets.event_title')
            ->select('tickets.*', 'events.title as snapshot_title', 'events.starts_at as snapshot_date',
                'events.venue_name as snapshot_venue', 'events.venue_address as snapshot_address',
                'event_zones.name as snapshot_zone')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row) {
                        $quantity = max(1, (int) $row->quantity);
                        $cents = (int) round((float) $row->total_price * 100);
                        $baseCents = intdiv($cents, $quantity);
                        $remainder = $cents % $quantity;
                        $snapshot = [
                            'event_title' => $row->snapshot_title,
                            'event_starts_at' => $row->snapshot_date,
                            'venue_name' => $row->snapshot_venue,
                            'venue_address' => $row->snapshot_address,
                            'zone_name' => $row->snapshot_zone,
                            'quantity' => 1,
                        ];
                        for ($index = 0; $index < $quantity; $index++) {
                            $price = ($baseCents + ($index < $remainder ? 1 : 0)) / 100;
                            $values = array_merge($snapshot, ['unit_price' => $price, 'total_price' => $price]);
                            if ($index === 0) {
                                DB::table('tickets')->where('id', $row->id)->update($values);
                            } else {
                                DB::table('tickets')->insert(array_merge($values, [
                                    'user_id' => $row->user_id,
                                    'event_zone_id' => $row->event_zone_id,
                                    'code' => 'TICK-'.substr($row->created_at, 0, 4).'-'.strtoupper(bin2hex(random_bytes(8))),
                                    'created_at' => $row->created_at,
                                    'updated_at' => $row->updated_at,
                                ]));
                            }
                        }
                    });
                }
            }, 'tickets.id', 'id');
    }

    public function down(): void
    {
        // Los tickets individualizados se conservan: no revertir ni borrar ventas.
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['event_title', 'event_starts_at', 'venue_name', 'venue_address', 'zone_name', 'unit_price']);
        });
    }
};

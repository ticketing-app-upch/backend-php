<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('marketing_opt_in')->nullable());
        Schema::table('client_profiles', fn (Blueprint $table) => $table->json('details')->nullable());
        Schema::table('organizer_profiles', fn (Blueprint $table) => $table->json('details')->nullable());
        Schema::table('venues', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique();
            $table->string('city')->default('');
            $table->string('map_image')->nullable();
        });
        Schema::table('events', function (Blueprint $table) {
            $table->string('city')->default('');
            $table->text('image_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('max_per_order')->default(6);
        });
        DB::table('events')->update(['published_at' => DB::raw('created_at')]);
        Schema::table('event_banners', function (Blueprint $table) {
            $table->longText('secondary_content')->nullable();
        });
        Schema::create('ticket_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 36)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('buyer_name');
            $table->string('event_name');
            $table->dateTime('event_starts_at');
            $table->string('event_venue', 512);
            $table->string('status')->default('CONFIRMADA');
            $table->string('payment_method')->default('CARD');
            $table->string('idempotency_key', 128)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->json('lines');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('fee', 12, 2);
            $table->decimal('total', 12, 2);
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('ticket_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('service_fee', 10, 2)->default(0);
        });

        // No se inventa una agrupación histórica: cada ticket anterior es una compra
        // conservada con su código, importe y snapshots, sin volver a descontar stock.
        DB::table('tickets')->join('users', 'users.id', '=', 'tickets.user_id')
            ->join('event_zones', 'event_zones.id', '=', 'tickets.event_zone_id')
            ->join('events', 'events.id', '=', 'event_zones.event_id')
            ->select('tickets.*', 'users.full_name as buyer_name', 'events.id as legacy_event_id',
                'events.title as legacy_title', 'events.starts_at as legacy_date', 'events.venue_name as legacy_venue',
                'event_zones.name as legacy_zone')
            ->whereNull('tickets.ticket_order_id')->chunkById(100, function ($tickets) {
                foreach ($tickets as $ticket) {
                    $orderId = DB::table('ticket_orders')->insertGetId([
                        'code' => $ticket->code, 'user_id' => $ticket->user_id, 'event_id' => $ticket->legacy_event_id,
                        'buyer_name' => $ticket->buyer_name, 'event_name' => $ticket->event_title ?? $ticket->legacy_title,
                        'event_starts_at' => $ticket->event_starts_at ?? $ticket->legacy_date,
                        'event_venue' => $ticket->venue_name ?? $ticket->legacy_venue, 'status' => 'CONFIRMADA',
                        'payment_method' => 'CARD', 'lines' => json_encode([[
                            'zoneId' => (string) $ticket->event_zone_id, 'zoneName' => $ticket->zone_name ?? $ticket->legacy_zone,
                            'unitPrice' => (float) ($ticket->unit_price ?? $ticket->total_price), 'quantity' => $ticket->quantity,
                        ]], JSON_THROW_ON_ERROR),
                        'subtotal' => $ticket->total_price, 'fee' => 0, 'total' => $ticket->total_price,
                        'created_at' => $ticket->created_at, 'updated_at' => $ticket->updated_at,
                    ]);
                    DB::table('tickets')->where('id', $ticket->id)->update(['ticket_order_id' => $orderId]);
                }
            }, 'tickets.id', 'id');
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_order_id');
            $table->dropColumn('service_fee');
        });
        Schema::dropIfExists('ticket_orders');
        Schema::table('event_banners', fn (Blueprint $table) => $table->dropColumn('secondary_content'));
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['city', 'image_url', 'published_at', 'max_per_order']));
        Schema::table('venues', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'city', 'map_image']);
        });
        Schema::table('organizer_profiles', fn (Blueprint $table) => $table->dropColumn('details'));
        Schema::table('client_profiles', fn (Blueprint $table) => $table->dropColumn('details'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('marketing_opt_in'));
    }
};

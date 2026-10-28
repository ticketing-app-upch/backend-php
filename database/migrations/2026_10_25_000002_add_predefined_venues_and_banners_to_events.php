<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('venue_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['venue_id', 'starts_at']);
        });
        Schema::table('event_zones', function (Blueprint $table) {
            $table->foreignId('venue_zone_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['event_id', 'venue_zone_id']);
        });
        Schema::create('event_banners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            // Base64 evita diferencias BLOB/bytea y persiste en el Postgres de Render.
            $table->longText('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_banners');
        Schema::table('event_zones', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'venue_zone_id']);
            $table->dropConstrainedForeignId('venue_zone_id');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['venue_id', 'starts_at']);
            $table->dropConstrainedForeignId('venue_id');
        });
    }
};

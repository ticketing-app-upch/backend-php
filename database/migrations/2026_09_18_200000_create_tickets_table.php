<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('event_zone_id')->constrained('event_zones')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->decimal('total_price', 10, 2);
            $table->string('code', 36)->unique();
            $table->timestamps();

            $table->index(['user_id', 'event_zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};

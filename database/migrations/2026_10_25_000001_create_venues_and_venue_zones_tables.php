<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('address');
            $table->unsignedInteger('capacity');
            $table->timestamps();
        });
        Schema::create('venue_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->json('map_shape');
            $table->timestamps();
            $table->unique(['venue_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_zones');
        Schema::dropIfExists('venues');
    }
};

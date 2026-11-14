<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_purchases', function (Blueprint $table) {
            $table->uuid('turn_id')->primary();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_order_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('sold_out');
            $table->timestamp('synced_at')->nullable();
            $table->index(['event_id', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_purchases');
    }
};

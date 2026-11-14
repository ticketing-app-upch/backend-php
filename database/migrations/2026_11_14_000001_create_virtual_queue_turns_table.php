<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_queue_turns', function (Blueprint $table) {
            $table->id();
            $table->uuid('turn_id')->unique();
            // Laravel holds the event lock during calls to Python. A foreign key
            // would make a turn insert wait for the transaction making that call.
            $table->string('event_id', 20);
            $table->string('user_id', 20);
            $table->string('status', 10);
            $table->double('joined_at');
            $table->integer('initial_position');
            $table->double('admitted_at')->nullable();
            $table->bigInteger('expires_at')->nullable();
            $table->bigInteger('payment_opened_at')->nullable();

            $table->index(['event_id', 'user_id', 'id']);
            $table->index(['event_id', 'status', 'id']);
            $table->index(['event_id', 'admitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_queue_turns');
    }
};

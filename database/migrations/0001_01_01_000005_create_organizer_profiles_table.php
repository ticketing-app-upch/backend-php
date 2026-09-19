<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organizer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('org_type');
            $table->string('display_name');
            $table->string('tax_id')->unique();
            $table->string('legal_name')->nullable();
            $table->string('rep_name');
            $table->string('phone');
            $table->string('country', 2);
            $table->string('city')->nullable();
            $table->string('website')->nullable();
            $table->string('verification_status')->default('PENDING');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizer_profiles');
    }
};

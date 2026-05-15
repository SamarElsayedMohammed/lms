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
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('device_type', ['web', 'android', 'ios', 'desktop']);
            $table->string('device_id'); // Unique identifier for the physical device
            $table->string('device_name')->nullable(); // e.g. "Samsung Galaxy S24", "iPhone 15"
            $table->timestamps();

            // Each user can have max 1 device per type
            $table->unique(['user_id', 'device_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};

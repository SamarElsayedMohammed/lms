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
        // First, rename the existing notifications table to preserve the data
        Schema::rename('notifications', 'legacy_notifications');

        // Create the new Laravel notifications table with proper structure
        Schema::create('notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the Laravel notifications table
        Schema::dropIfExists('notifications');
        
        // Restore the original notifications table
        Schema::rename('legacy_notifications', 'notifications');
    }
};
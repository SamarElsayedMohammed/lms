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
        Schema::table('user_devices', function (Blueprint $table) {
            // Drop legacy unique constraint on (user_id, device_type)
            $table->dropUnique(['user_id', 'device_type']);

            // Add unique constraint on (user_id, device_id)
            $table->unique(['user_id', 'device_id']);
            $table->index(['user_id', 'device_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'device_id']);
            $table->dropIndex(['user_id', 'device_type']);
            $table->unique(['user_id', 'device_type']);
        });
    }
};

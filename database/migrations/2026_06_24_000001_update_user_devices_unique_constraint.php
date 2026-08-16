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
            // Drop foreign key first so MySQL allows dropping the index needed by it
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable) {}
        });

        Schema::table('user_devices', function (Blueprint $table) {
            // Drop legacy unique constraint on (user_id, device_type)
            try {
                $table->dropUnique('user_devices_user_id_device_type_unique');
            } catch (\Throwable) {
                try {
                    $table->dropUnique(['user_id', 'device_type']);
                } catch (\Throwable) {}
            }

            // Add unique constraint on (user_id, device_id)
            try {
                $table->unique(['user_id', 'device_id'], 'user_devices_user_id_device_id_unique');
            } catch (\Throwable) {}

            // Add index on (user_id, device_type)
            try {
                $table->index(['user_id', 'device_type'], 'user_devices_user_id_device_type_index');
            } catch (\Throwable) {}

            // Re-add foreign key
            try {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            } catch (\Throwable) {}
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable) {}
        });

        Schema::table('user_devices', function (Blueprint $table) {
            try {
                $table->dropUnique('user_devices_user_id_device_id_unique');
            } catch (\Throwable) {
                try {
                    $table->dropUnique(['user_id', 'device_id']);
                } catch (\Throwable) {}
            }

            try {
                $table->dropIndex('user_devices_user_id_device_type_index');
            } catch (\Throwable) {
                try {
                    $table->dropIndex(['user_id', 'device_type']);
                } catch (\Throwable) {}
            }

            try {
                $table->unique(['user_id', 'device_type'], 'user_devices_user_id_device_type_unique');
            } catch (\Throwable) {}

            try {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            } catch (\Throwable) {}
        });
    }
};

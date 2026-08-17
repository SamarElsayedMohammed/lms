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
        if (! Schema::hasTable('user_devices')) {
            return;
        }

        Schema::table('user_devices', function (Blueprint $table) {
            // 1. Drop foreign key first so MySQL allows dropping the unique index
            $table->dropForeign(['user_id']);

            // 2. Drop existing unique index on (user_id, device_type)
            $table->dropUnique('user_devices_user_id_device_type_unique');

            // 3. Add new unique constraint on (user_id, device_id) and index on (user_id, device_type)
            $table->unique(['user_id', 'device_id'], 'user_devices_user_id_device_id_unique');
            $table->index(['user_id', 'device_type'], 'user_devices_user_id_device_type_index');

            // 4. Re-add foreign key constraint
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('user_devices')) {
            return;
        }

        Schema::table('user_devices', function (Blueprint $table) {
            // 1. Drop foreign key first
            $table->dropForeign(['user_id']);

            // 2. Revert unique index and drop new index
            $table->dropUnique('user_devices_user_id_device_id_unique');
            $table->dropIndex('user_devices_user_id_device_type_index');
            $table->unique(['user_id', 'device_type'], 'user_devices_user_id_device_type_unique');

            // 3. Re-add foreign key constraint
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};

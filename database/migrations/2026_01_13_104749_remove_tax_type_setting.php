<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove tax_type setting if it exists
        DB::table('settings')
            ->where('name', 'tax_type')
            ->delete();

        // Clear cache to remove cached settings
        Cache::forget('settings');
        Cache::forget('e_lms_cache_settings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to restore deprecated setting
    }
};

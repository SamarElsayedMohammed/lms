<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert the instructor_mode setting if it doesn't exist
        DB::table('settings')->updateOrInsert(
            ['name' => 'instructor_mode'],
            [
                'name' => 'instructor_mode',
                'value' => 'multi',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the instructor_mode setting
        DB::table('settings')->where('name', 'instructor_mode')->delete();
    }
};

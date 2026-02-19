<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['name' => 'website_copyright'],
            [
                'name' => 'website_copyright',
                'value' => 'Copyright &copy; {year} eLMS. All rights reserved.',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->where('name', 'website_copyright')->delete();
    }
};

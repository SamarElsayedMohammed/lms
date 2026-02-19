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
        // Insert the commission settings if they don't exist
        $commissionSettings = [
            [
                'name' => 'individual_admin_commission',
                'value' => '5',
                'type' => 'number',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'team_admin_commission',
                'value' => '10',
                'type' => 'number',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ];

        foreach ($commissionSettings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['name' => $setting['name']],
                $setting
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the commission settings
        DB::table('settings')->whereIn('name', [
            'individual_admin_commission',
            'team_admin_commission'
        ])->delete();
    }
};

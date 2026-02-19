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
        // Insert policy settings if they don't exist
        $policySettings = [
            [
                'name' => 'terms_and_conditions',
                'value' => '',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'privacy_policy',
                'value' => '',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'cookie_policy',
                'value' => '',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ];

        foreach ($policySettings as $setting) {
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
        // Remove policy settings
        DB::table('settings')->whereIn('name', [
            'terms_and_conditions',
            'privacy_policy',
            'cookie_policy'
        ])->delete();
    }
};

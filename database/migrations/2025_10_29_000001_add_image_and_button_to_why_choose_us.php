<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add Image, Button Text and Button Link to Why Choose Us settings
        $settings = [
            [
                'name' => 'why_choose_us_image',
                'value' => null,
                'type' => 'file',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'why_choose_us_button_text',
                'value' => 'Join for Free',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'why_choose_us_button_link',
                'value' => '/register',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($settings as $setting) {
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
        // Remove Image, Button Text and Button Link from Why Choose Us settings
        DB::table('settings')->whereIn('name', [
            'why_choose_us_image',
            'why_choose_us_button_text',
            'why_choose_us_button_link',
        ])->delete();
    }
};


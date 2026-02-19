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
        // Add Why Choose Us settings
        $settings = [
            [
                'name' => 'why_choose_us_title',
                'value' => 'Transform Knowledge Sharing with Our Cutting-Edge LMS',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'why_choose_us_description',
                'value' => 'Our online course platform redefines learning with innovative features like interactive content, real-time progress tracking, and seamless integrations.',
                'type' => 'textarea',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'why_choose_us_point_1',
                'value' => 'Easily create engaging courses with user-friendly tools.',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'why_choose_us_point_2',
                'value' => 'Boost engagement with interactive quizzes and videos.',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'why_choose_us_point_3',
                'value' => 'Personalize experiences with customizable dashboards.',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'why_choose_us_point_4',
                'value' => 'Track progress effectively with detailed analytics.',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'why_choose_us_point_5',
                'value' => 'Rely on 24/7 support for uninterrupted learning.',
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
        // Remove Why Choose Us settings
        DB::table('settings')->whereIn('name', [
            'why_choose_us_title',
            'why_choose_us_description',
            'why_choose_us_point_1',
            'why_choose_us_point_2',
            'why_choose_us_point_3',
            'why_choose_us_point_4',
            'why_choose_us_point_5',
        ])->delete();
    }
};


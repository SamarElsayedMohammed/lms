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
        // Add Become Instructor settings
        $settings = [
            [
                'name' => 'become_instructor_title',
                'value' => 'Unlock Your Teaching Potential: Join Our Instructor Community',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'become_instructor_description',
                'value' => 'Join our platform and share your expertise with students worldwide. Follow simple steps to create your course and start teaching today!',
                'type' => 'textarea',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'become_instructor_button_text',
                'value' => 'Become an Instructor',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'become_instructor_button_link',
                'value' => '/instructor/register',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Step 1
            [
                'name' => 'become_instructor_step_1_title',
                'value' => 'Sign Up and Create an Account',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'become_instructor_step_1_description',
                'value' => 'Register on the platform and complete your profile.',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'become_instructor_step_1_image',
                'value' => null,
                'type' => 'file',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Step 2
            [
                'name' => 'become_instructor_step_2_title',
                'value' => 'Fill Out the Instructor Application Form',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'become_instructor_step_2_description',
                'value' => 'Provide details about your expertise, teaching experience, and the courses you plan to create.',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'become_instructor_step_2_image',
                'value' => null,
                'type' => 'file',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Step 3
            [
                'name' => 'become_instructor_step_3_title',
                'value' => 'Prepare Your Course Content',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'become_instructor_step_3_description',
                'value' => 'Plan and create engaging course material, including lectures, videos, and resources.',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'become_instructor_step_3_image',
                'value' => null,
                'type' => 'file',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Step 4
            [
                'name' => 'become_instructor_step_4_title',
                'value' => 'Upload and Submit Your Courses',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'become_instructor_step_4_description',
                'value' => 'Use the platform\'s tools to upload and organize your course, then submit it for review and approval.',
                'type' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'become_instructor_step_4_image',
                'value' => null,
                'type' => 'file',
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
        // Remove Become Instructor settings
        DB::table('settings')->whereIn('name', [
            'become_instructor_title',
            'become_instructor_description',
            'become_instructor_button_text',
            'become_instructor_button_link',
            'become_instructor_step_1_title',
            'become_instructor_step_1_description',
            'become_instructor_step_1_image',
            'become_instructor_step_2_title',
            'become_instructor_step_2_description',
            'become_instructor_step_2_image',
            'become_instructor_step_3_title',
            'become_instructor_step_3_description',
            'become_instructor_step_3_image',
            'become_instructor_step_4_title',
            'become_instructor_step_4_description',
            'become_instructor_step_4_image',
        ])->delete();
    }
};


<?php

namespace Database\Seeders;

use App\Models\Certificate;
use Illuminate\Database\Seeder;

class CertificateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $certificates = [
            [
                'name' => 'Course Completion Certificate',
                'description' => 'Default certificate template for course completion',
                'type' => 'course_completion',
                'title' => 'Certificate of Completion',
                'subtitle' => 'This is to certify that',
                'signature_text' => 'Director of Education',
                'is_active' => true,
            ],
            [
                'name' => 'Exam Completion Certificate',
                'description' => 'Default certificate template for exam completion',
                'type' => 'exam_completion',
                'title' => 'Certificate of Achievement',
                'subtitle' => 'This is to certify that',
                'signature_text' => 'Examination Board',
                'is_active' => true,
            ],
            [
                'name' => 'Custom Certificate Template',
                'description' => 'Custom certificate template for special achievements',
                'type' => 'custom',
                'title' => 'Certificate of Excellence',
                'subtitle' => 'In recognition of outstanding performance',
                'signature_text' => 'Academic Director',
                'is_active' => true,
            ],
        ];

        foreach ($certificates as $certificate) {
            Certificate::create($certificate);
        }
    }
}

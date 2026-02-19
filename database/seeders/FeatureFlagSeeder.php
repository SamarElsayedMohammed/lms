<?php

namespace Database\Seeders;

use App\Models\FeatureFlag;
use Illuminate\Database\Seeder;

class FeatureFlagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $flags = [
            [
                'key' => 'lecture_attachments',
                'name' => 'ملفات مرفقة تحت الفيديو',
                'description' => 'إظهار ملفات مرفقة تحت كل فيديو',
                'is_enabled' => false,
            ],
            [
                'key' => 'affiliate_system',
                'name' => 'نظام التسويق بالعمولة',
                'description' => 'تفعيل نظام التسويق بالعمولة',
                'is_enabled' => false,
            ],
            [
                'key' => 'video_progress_enforcement',
                'name' => 'إلزام مشاهدة 85%',
                'description' => 'إلزام الطالب بمشاهدة 85% من الفيديو قبل فتح الدرس التالي',
                'is_enabled' => true,
            ],
            [
                'key' => 'comments_require_approval',
                'name' => 'موافقة الأدمن على التعليقات',
                'description' => 'التعليقات تحتاج موافقة الأدمن قبل الظهور',
                'is_enabled' => true,
            ],
            [
                'key' => 'ratings_require_approval',
                'name' => 'موافقة الأدمن على التقييمات',
                'description' => 'التقييمات تحتاج موافقة الأدمن قبل الظهور',
                'is_enabled' => true,
            ],
        ];

        foreach ($flags as $data) {
            FeatureFlag::updateOrCreate(
                ['key' => $data['key']],
                $data
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\FeatureSection;
use App\Models\Slider;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_fetch_mobile_home(): void
    {
        $response = $this->getJson('/api/mobile/home');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'header' => [
                        'user',
                        'audience_state',
                        'unread_notifications',
                    ],
                    'sections',
                ],
            ]);

        $this->assertEquals('guest', $response->json('data.header.audience_state'));
    }

    public function test_banners_exclude_inactive_and_expired(): void
    {
        // 1. Active banner
        Slider::create([
            'image' => 'active.jpg',
            'title' => 'Active Banner',
            'order' => 1,
            'is_active' => true,
        ]);

        // 2. Disabled banner
        Slider::create([
            'image' => 'disabled.jpg',
            'title' => 'Disabled Banner',
            'order' => 2,
            'is_active' => false,
        ]);

        // 3. Expired banner
        Slider::create([
            'image' => 'expired.jpg',
            'title' => 'Expired Banner',
            'order' => 3,
            'is_active' => true,
            'start_at' => now()->subDays(10),
            'end_at' => now()->subDays(2),
        ]);

        $response = $this->getJson('/api/mobile/home');
        $response->assertStatus(200);

        $sections = collect($response->json('data.sections'));
        $heroSection = $sections->firstWhere('type', 'hero');

        if ($heroSection) {
            $titles = collect($heroSection['data'])->pluck('title');
            $this->assertTrue($titles->contains('Active Banner'));
            $this->assertFalse($titles->contains('Disabled Banner'));
            $this->assertFalse($titles->contains('Expired Banner'));
        }
    }

    public function test_free_courses_section_returns_canonical_free_data(): void
    {
        $user = User::factory()->create(['is_active' => 1]);

        $category = Category::create([
            'name' => 'Development',
            'slug' => 'development',
            'status' => 1,
        ]);

        $freeCourse = Course::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => 'Free Skill Course',
            'slug' => 'free-skill-course',
            'level' => 'all_levels',
            'is_active' => 1,
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_free' => 1,
            'price' => 0,
            'course_type' => 'free',
        ]);

        $paidCourse = Course::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => 'Paid Skill Course',
            'slug' => 'paid-skill-course',
            'level' => 'all_levels',
            'is_active' => 1,
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_free' => 0,
            'price' => 500,
            'course_type' => 'paid',
        ]);

        $response = $this->getJson('/api/mobile/home');
        $response->assertStatus(200);

        $sections = collect($response->json('data.sections'));
        $freeSection = $sections->firstWhere('type', 'free_courses');

        if ($freeSection) {
            $courseTitles = collect($freeSection['data'])->pluck('title');
            $this->assertTrue($courseTitles->contains('Free Skill Course'));
            $this->assertFalse($courseTitles->contains('Paid Skill Course'));
        }
    }

    public function test_admin_can_manage_mobile_home_sections_banners_and_preview(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['is_active' => 1]);
        $admin->assignRole('Super Admin');

        // 1. Overview
        $overviewRes = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/mobile-home/overview');
        $overviewRes->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonStructure([
                'data' => [
                    'active_sections_count',
                    'total_sections_count',
                    'active_banners_count',
                    'last_updated',
                ],
            ]);

        // 2. Seed default sections
        $seedRes = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/mobile-home/sections/seed-defaults');
        $seedRes->assertStatus(200)->assertJsonPath('status', true);
        $this->assertGreaterThanOrEqual(8, count($seedRes->json('data')));

        // 3. Create section with mobile-specific type & layout
        $createSecRes = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/mobile-home/sections', [
            'title' => 'قسم تجريبي للجوال',
            'subtitle' => 'وصف تجريبي',
            'type' => 'featured_courses',
            'layout' => 'grid',
            'audience' => 'everyone',
            'limit' => 6,
            'is_active' => true,
        ]);
        $createSecRes->assertStatus(201)
            ->assertJsonPath('data.title', 'قسم تجريبي للجوال')
            ->assertJsonPath('data.layout', 'grid');

        $secId = $createSecRes->json('data.id');

        // 4. Update section (including type = courses and show_on_web)
        $updateSecRes = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/mobile-home/sections/{$secId}", [
            'title' => 'قسم محدث للجوال',
            'type' => 'courses',
            'show_on_web' => true,
        ]);
        $updateSecRes->assertStatus(200)
            ->assertJsonPath('data.title', 'قسم محدث للجوال')
            ->assertJsonPath('data.show_on_web', true);

        // 5. Create banner without file upload (using image_url / mobile_image_url)
        $createBannerRes = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/mobile-home/banners', [
            'title' => 'عرض الصيف',
            'subtitle' => 'خصم 50% على الباقات',
            'mobile_image_url' => 'https://api.skillso.net/storage/sliders/test.jpg',
            'cta_label' => 'اشترك الآن',
            'cta_type' => 'subscription_plans',
            'audience' => 'non_subscriber',
            'is_active' => true,
        ]);
        $createBannerRes->assertStatus(201)
            ->assertJsonPath('data.title', 'عرض الصيف')
            ->assertJsonPath('data.audience', 'non_subscriber');

        $bannerId = $createBannerRes->json('data.id');

        // 6. Reorder banners
        $reorderBannerRes = $this->actingAs($admin, 'sanctum')->putJson('/api/admin/mobile-home/banners/reorder', [
            'orders' => [
                ['id' => $bannerId, 'order' => 1],
            ],
        ]);
        $reorderBannerRes->assertStatus(200)->assertJsonPath('status', true);

        // 7. Admin preview with audience simulator
        $previewSubscriberRes = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/mobile-home/preview?preview_audience=subscriber');
        $previewSubscriberRes->assertStatus(200)
            ->assertJsonPath('data.header.audience_state', 'subscriber');

        // 8. Learning settings
        $learningRes = $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/mobile-learning/settings', [
                'downloads_enabled' => true,
                'offline_sync_enabled' => true,
                'certificates_tab_enabled' => true,
                'max_devices_per_user' => 4,
                'download_expiry_days' => 45,
            ]);
        $learningRes->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.max_devices_per_user', 4)
            ->assertJsonPath('data.download_expiry_days', 45);
    }
}


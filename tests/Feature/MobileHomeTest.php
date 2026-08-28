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
}

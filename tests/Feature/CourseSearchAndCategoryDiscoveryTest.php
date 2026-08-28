<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseSearchAndCategoryDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;
    private Category $programmingCategory;
    private Category $mobileChildCategory;
    private Category $designCategory;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->instructor = User::factory()->create([
            'name'      => 'أحمد مدرب',
            'slug'      => 'ahmed-instructor',
            'email'     => 'instructor@skillso.org',
            'is_active' => 1,
        ]);
        $this->instructor->assignRole('instructor');

        $this->programmingCategory = Category::create([
            'name'      => 'برمجة وتطوير',
            'slug'      => 'programming-development',
            'status'    => true,
            'sequence'  => 1,
        ]);

        $this->mobileChildCategory = Category::create([
            'name'               => 'تطوير تطبيقات الجوال',
            'slug'               => 'mobile-app-development',
            'parent_category_id' => $this->programmingCategory->id,
            'status'             => true,
            'sequence'           => 1,
        ]);

        $this->designCategory = Category::create([
            'name'      => 'تصميم تجربة المستخدم',
            'slug'      => 'ui-ux-design',
            'status'    => true,
            'sequence'  => 2,
        ]);
    }

    public function test_guest_can_search_courses_with_text_query(): void
    {
        $course1 = Course::factory()->create([
            'title'             => 'دورة فلاتر المتقدمة لبناء التطبيقات',
            'slug'              => 'advanced-flutter-course',
            'short_description' => 'تعلم فلاتر والبرمجة باحتراف',
            'user_id'           => $this->instructor->id,
            'category_id'       => $this->mobileChildCategory->id,
            'is_active'         => 1,
            'status'            => 'publish',
            'approval_status'   => 'approved',
            'course_type'       => 'paid',
            'price'             => 299,
        ]);

        $course2 = Course::factory()->create([
            'title'             => 'دورة تصميم واجهات فيجما',
            'slug'              => 'figma-ui-design',
            'short_description' => 'تعلم التصميم الحديث',
            'user_id'           => $this->instructor->id,
            'category_id'       => $this->designCategory->id,
            'is_active'         => 1,
            'status'            => 'publish',
            'approval_status'   => 'approved',
            'course_type'       => 'paid',
            'price'             => 199,
        ]);

        $response = $this->getJson('/api/get-courses?search=فلاتر');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($course1->id, $data[0]['id']);
        $this->assertEquals('دورة فلاتر المتقدمة لبناء التطبيقات', $data[0]['title']);
    }

    public function test_category_id_filter_includes_child_categories(): void
    {
        $parentCourse = Course::factory()->create([
            'title'             => 'أساسيات علوم الحاسب',
            'slug'              => 'cs-fundamentals',
            'user_id'           => $this->instructor->id,
            'category_id'       => $this->programmingCategory->id,
            'is_active'         => 1,
            'status'            => 'publish',
            'approval_status'   => 'approved',
            'course_type'       => 'free',
            'price'             => 0,
        ]);

        $childCourse = Course::factory()->create([
            'title'             => 'تطوير تطبيقات أندرويد و iOS',
            'slug'              => 'mobile-app-dev',
            'user_id'           => $this->instructor->id,
            'category_id'       => $this->mobileChildCategory->id,
            'is_active'         => 1,
            'status'            => 'publish',
            'approval_status'   => 'approved',
            'course_type'       => 'paid',
            'price'             => 350,
        ]);

        $otherCourse = Course::factory()->create([
            'title'             => 'مبادئ تصميم UI UX',
            'slug'              => 'ui-ux-principles',
            'user_id'           => $this->instructor->id,
            'category_id'       => $this->designCategory->id,
            'is_active'         => 1,
            'status'            => 'publish',
            'approval_status'   => 'approved',
            'course_type'       => 'free',
            'price'             => 0,
        ]);

        // Filter by parent programming category ID
        $response = $this->getJson('/api/get-courses?category_id=' . $this->programmingCategory->id);

        $response->assertOk();
        $data = $response->json('data');
        $courseIds = array_column($data, 'id');

        $this->assertContains($parentCourse->id, $courseIds);
        $this->assertContains($childCourse->id, $courseIds);
        $this->assertNotContains($otherCourse->id, $courseIds);
    }

    public function test_category_slug_filter_returns_correct_courses(): void
    {
        $designCourse = Course::factory()->create([
            'title'             => 'كورس تجربة المستخدم الحديثة',
            'slug'              => 'modern-ux-course',
            'user_id'           => $this->instructor->id,
            'category_id'       => $this->designCategory->id,
            'is_active'         => 1,
            'status'            => 'publish',
            'approval_status'   => 'approved',
            'course_type'       => 'paid',
            'price'             => 150,
        ]);

        $response = $this->getJson('/api/get-courses?category_slug=ui-ux-design');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($designCourse->id, $data[0]['id']);
    }

    public function test_arabic_search_normalization_matches_orthographic_variations(): void
    {
        $course = Course::factory()->create([
            'title'             => 'إدارة المشاريع البرمجية باحتراف',
            'slug'              => 'software-project-management',
            'short_description' => 'تعلم القيادة وإدارة الفرق',
            'user_id'           => $this->instructor->id,
            'category_id'       => $this->programmingCategory->id,
            'is_active'         => 1,
            'status'            => 'publish',
            'approval_status'   => 'approved',
            'course_type'       => 'free',
            'price'             => 0,
        ]);

        // Search with bare alef 'ادارة' -> should match 'إدارة'
        $response1 = $this->getJson('/api/get-courses?search=ادارة');
        $response1->assertOk();
        $data1 = $response1->json('data');
        $this->assertNotEmpty($data1);
        $this->assertEquals($course->id, $data1[0]['id']);

        // Search with 'برمجيه' (ha) -> should match 'البرمجية' (ta marbuta)
        $response2 = $this->getJson('/api/get-courses?search=برمجيه');
        $response2->assertOk();
        $data2 = $response2->json('data');
        $this->assertNotEmpty($data2);
        $this->assertEquals($course->id, $data2[0]['id']);
    }

    public function test_combined_search_query_and_category_filter(): void
    {
        $progCourse = Course::factory()->create([
            'title'             => 'مقدمة في الذكاء الاصطناعي للبرمجة',
            'slug'              => 'intro-ai-programming',
            'user_id'           => $this->instructor->id,
            'category_id'       => $this->programmingCategory->id,
            'is_active'         => 1,
            'status'            => 'publish',
            'approval_status'   => 'approved',
            'course_type'       => 'paid',
            'price'             => 400,
        ]);

        $designCourse = Course::factory()->create([
            'title'             => 'الذكاء الاصطناعي في تصميم الواجهات',
            'slug'              => 'ai-in-ui-design',
            'user_id'           => $this->instructor->id,
            'category_id'       => $this->designCategory->id,
            'is_active'         => 1,
            'status'            => 'publish',
            'approval_status'   => 'approved',
            'course_type'       => 'free',
            'price'             => 0,
        ]);

        // Search 'الذكاء' within programming category only
        $response = $this->getJson('/api/get-courses?search=الذكاء&category_id=' . $this->programmingCategory->id);
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($progCourse->id, $data[0]['id']);
    }

    public function test_course_type_free_and_paid_filters(): void
    {
        Course::factory()->create([
            'title'             => 'دورة مجانية تمهيدية',
            'slug'              => 'free-intro-course',
            'user_id'           => $this->instructor->id,
            'category_id'       => $this->programmingCategory->id,
            'is_active'         => 1,
            'status'            => 'publish',
            'approval_status'   => 'approved',
            'course_type'       => 'free',
            'price'             => 0,
        ]);

        Course::factory()->create([
            'title'             => 'دورة متقدمة مدفوعة',
            'slug'              => 'paid-advanced-course',
            'user_id'           => $this->instructor->id,
            'category_id'       => $this->programmingCategory->id,
            'is_active'         => 1,
            'status'            => 'publish',
            'approval_status'   => 'approved',
            'course_type'       => 'paid',
            'price'             => 500,
        ]);

        $freeResponse = $this->getJson('/api/get-courses?course_type=free');
        $freeResponse->assertOk();
        $freeData = $freeResponse->json('data');
        $this->assertCount(1, $freeData);
        $this->assertEquals('free-intro-course', $freeData[0]['slug']);

        $paidResponse = $this->getJson('/api/get-courses?course_type=paid');
        $paidResponse->assertOk();
        $paidData = $paidResponse->json('data');
        $this->assertCount(1, $paidData);
        $this->assertEquals('paid-advanced-course', $paidData[0]['slug']);
    }
}

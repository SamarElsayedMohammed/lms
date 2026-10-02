<?php

namespace Tests\Feature\Chatbot;

use App\Models\Course\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatbotAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_visitor_config_returns_enabled_when_active(): void
    {
        $response = $this->getJson('/api/chatbot/config');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'enabled',
                    'name',
                ],
            ]);
    }

    public function test_course_config_returns_authoritative_disabled_reason_when_course_bot_disabled(): void
    {
        $course = Course::factory()->create([
            'chatbot_enabled' => false,
            'ai_knowledge_content' => 'Test content',
        ]);

        $response = $this->getJson("/api/chatbot/config/{$course->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'enabled' => false,
                    'available' => false,
                    'reason_code' => 'course_bot_disabled',
                ],
            ]);
    }

    public function test_course_config_returns_available_for_enrolled_student(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create([
            'chatbot_enabled' => true,
            'ai_knowledge_content' => 'Sample lesson material',
            'ai_processing_status' => 'ready',
        ]);
        $chapter = \App\Models\Course\CourseChapter\CourseChapter::factory()->create(['course_id' => $course->id]);
        \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::factory()->create(['course_chapter_id' => $chapter->id]);

        $order = \App\Models\Order::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'payment_method' => 'wallet',
            'total_price' => 100,
            'final_price' => 100,
            'order_number' => 'ORD-CHAT-' . $user->id,
        ]);
        \App\Models\OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $course->id,
            'price' => 100,
            'tax_price' => 0,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/chatbot/config/{$course->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'available' => true,
                    'student_authorized' => true,
                    'can_send_message' => true,
                ],
            ]);
    }

    public function test_course_catalog_exposes_ai_assistant_flags(): void
    {
        $course = Course::factory()->create([
            'chatbot_enabled' => true,
            'ai_knowledge_content' => 'Deep learning fundamentals',
            'ai_processing_status' => 'ready',
        ]);

        $response = $this->getJson("/api/get-course?course_id={$course->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'has_ai_assistant' => true,
                    'chatbot_enabled' => true,
                    'ai_processing_status' => 'ready',
                ],
            ]);
    }

    public function test_admin_course_show_preserves_ai_knowledge_content(): void
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole($role);

        $course = Course::factory()->create([
            'title' => 'Advanced Machine Learning',
            'chatbot_enabled' => true,
            'ai_knowledge_content' => 'Comprehensive syllabus for AI',
            'ai_processing_status' => 'ready',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/courses/{$course->id}");

        $response->assertStatus(200);
        $this->assertEquals('Comprehensive syllabus for AI', $response->json('data.ai_knowledge_content'));
        $this->assertTrue($response->json('data.chatbot_enabled'));
        $this->assertTrue($response->json('data.has_ai_assistant'));
    }

    public function test_admin_remove_ai_info_cleanses_knowledge_base_and_vector_chunks(): void
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole($role);

        $course = Course::factory()->create([
            'chatbot_enabled' => true,
            'ai_knowledge_content' => 'Existing notes',
            'ai_processing_status' => 'ready',
        ]);

        \App\Models\ChatbotKnowledgeBase::create([
            'course_id' => $course->id,
            'target_audience' => 'course',
            'title' => 'Course Notes',
            'content' => 'Existing notes',
            'is_active' => true,
            'processing_status' => 'ready',
        ]);

        \App\Models\ChatbotVectorChunk::create([
            'bot_type' => 'course',
            'course_id' => $course->id,
            'source_type' => 'text',
            'title' => 'Course Notes',
            'chunk_index' => 0,
            'chunk_text' => 'Existing chunk',
            'embedding' => json_encode([0.1, 0.2]),
            'token_count' => 10,
            'content_hash' => 'hash123',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/courses/{$course->id}/chatbot");

        $response->assertStatus(200);

        $course->refresh();
        $this->assertFalse($course->chatbot_enabled);
        $this->assertNull($course->ai_knowledge_content);
        $this->assertEquals('not_configured', $course->ai_processing_status);

        $this->assertEquals(0, \App\Models\ChatbotKnowledgeBase::where('course_id', $course->id)->count());
        $this->assertEquals(0, \App\Models\ChatbotVectorChunk::where('course_id', $course->id)->count());
    }
}

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

        // Attach student enrollment via UserCourseProgress
        \App\Models\UserCourseProgress::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'completed_items' => 1,
            'total_items' => 10,
            'progress_percentage' => 10,
            'status' => 'in_progress',
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
}

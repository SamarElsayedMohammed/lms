<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Course\Course;
use App\Models\Course\CourseProgress;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotTest extends TestCase
{
    use RefreshDatabase;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Mock the AI API calls so we don't actually hit Gemini/OpenAI
        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Mock AI Response']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);
    }

    public function test_course_chatbot_rejects_non_enrolled_users(): void
    {
        $course = Course::factory()->create([
            'ai_knowledge_content' => 'Test knowledge',
            'chatbot_enabled' => true,
            'course_type' => 'paid',
            'price' => 100,
        ]);

        $user = User::factory()->create(); // Not enrolled

        $this->actingAs($user)
            ->postJson('/api/chatbot/course-message', [
                'course_id' => $course->id,
                'message' => 'Hello',
            ])
            ->assertStatus(403)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'You must be enrolled in this course to use the assistant');
    }

    public function test_course_chatbot_allows_enrolled_users(): void
    {
        $course = Course::factory()->create([
            'ai_knowledge_content' => 'Test knowledge',
            'chatbot_enabled' => true,
            'course_type' => 'paid',
            'price' => 100,
        ]);

        $user = User::factory()->create();
        \App\Models\UserCourseProgress::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'completed_items' => 1,
            'total_items' => 10,
            'progress_percentage' => 10,
            'status' => 'in_progress',
        ]);

        $this->actingAs($user)
            ->postJson('/api/chatbot/course-message', [
                'course_id' => $course->id,
                'message' => 'Hello',
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', true);
    }

    public function test_global_chatbot_respects_chatbot_enabled_setting(): void
    {
        // Disable chatbot
        Setting::updateOrCreate(['name' => 'chatbot_enabled'], ['value' => '0', 'type' => 'boolean']);

        $this->postJson('/api/chatbot/message', [
            'message' => 'Hello'
        ])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Chatbot is currently disabled');

        // Enable chatbot
        Setting::updateOrCreate(['name' => 'chatbot_enabled'], ['value' => '1', 'type' => 'boolean']);

        $this->postJson('/api/chatbot/message', [
            'message' => 'Hello'
        ])
        ->assertStatus(200);
    }

    public function test_visitor_chatbot_works_end_to_end_with_session_id(): void
    {
        Setting::updateOrCreate(['name' => 'chatbot_enabled'], ['value' => '1', 'type' => 'boolean']);
        $sessionId = 'test-session-123';

        $response = $this->postJson('/api/chatbot/message', [
            'message' => 'Hello visitor'
        ], [
            'X-Chat-Session-ID' => $sessionId
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $conversationId = $data['conversation_id'];
        $this->assertNotNull($conversationId);

        // Verify it was logged under this session ID in the database
        $this->assertDatabaseHas('chatbot_conversations', [
            'id' => $conversationId,
            'session_id' => $sessionId,
            'user_id' => null,
        ]);

        $this->assertDatabaseHas('chatbot_messages', [
            'conversation_id' => $conversationId,
            'session_id' => $sessionId,
            'message' => 'Hello visitor',
        ]);
    }
}

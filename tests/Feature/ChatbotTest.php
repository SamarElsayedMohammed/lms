<?php

use App\Models\User;
use App\Models\Course\Course;
use App\Models\ChatbotKnowledgeBase;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function () {
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
});

test('course chatbot rejects non-enrolled users', function () {
    $course = Course::factory()->create([
        'ai_knowledge_content' => 'Test knowledge',
        'chatbot_enabled' => true,
    ]);
    
    $user = User::factory()->create(); // Not enrolled

    actingAs($user)
        ->postJson('/api/chatbot/course-message', [
            'course_id' => $course->id,
            'message' => 'Hello',
        ])
        ->assertStatus(403)
        ->assertJsonPath('status', false)
        ->assertJsonPath('message', 'You must be enrolled in this course to use the assistant');
});

test('course chatbot allows enrolled users', function () {
    $course = Course::factory()->create([
        'ai_knowledge_content' => 'Test knowledge',
        'chatbot_enabled' => true,
    ]);
    
    $user = User::factory()->create();
    $course->students()->attach($user->id, ['status' => 'active']); // Enrolled

    actingAs($user)
        ->postJson('/api/chatbot/course-message', [
            'course_id' => $course->id,
            'message' => 'Hello',
        ])
        ->assertStatus(200)
        ->assertJsonPath('status', true);
});

test('global chatbot respects chatbot_enabled setting', function () {
    // Disable chatbot
    Setting::updateOrCreate(['name' => 'chatbot_enabled'], ['value' => '0', 'type' => 'boolean']);
    
    postJson('/api/chatbot/message', [
        'message' => 'Hello'
    ])
    ->assertStatus(403)
    ->assertJsonPath('message', 'Chatbot is currently disabled');
    
    // Enable chatbot
    Setting::updateOrCreate(['name' => 'chatbot_enabled'], ['value' => '1', 'type' => 'boolean']);
    
    postJson('/api/chatbot/message', [
        'message' => 'Hello'
    ])
    ->assertStatus(200);
});

test('visitor chatbot works end-to-end with session id', function () {
    Setting::updateOrCreate(['name' => 'chatbot_enabled'], ['value' => '1', 'type' => 'boolean']);
    $sessionId = 'test-session-123';
    
    $response = postJson('/api/chatbot/message', [
        'message' => 'Hello visitor'
    ], [
        'X-Chat-Session-ID' => $sessionId
    ]);
    
    $response->assertStatus(200);
    $data = $response->json('data');
    
    $conversationId = $data['conversation_id'];
    expect($conversationId)->not->toBeNull();
    
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
});

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
        $chapter = \App\Models\Course\CourseChapter\CourseChapter::factory()->create(['course_id' => $course->id]);
        \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::factory()->create(['course_chapter_id' => $chapter->id]);

        $user = User::factory()->create();
        $order = \App\Models\Order::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'payment_method' => 'wallet',
            'total_price' => 100,
            'final_price' => 100,
            'order_number' => 'ORD-CHAT-BOT-' . $user->id,
        ]);
        \App\Models\OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $course->id,
            'price' => 100,
            'tax_price' => 0,
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

    public function test_general_chatbot_rejects_course_conversation_session(): void
    {
        Setting::updateOrCreate(['name' => 'chatbot_enabled'], ['value' => '1', 'type' => 'boolean']);
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $courseConv = \App\Models\ChatbotConversation::create([
            'user_id' => $user->id,
            'title' => 'Course Session',
            'type' => 'course',
            'course_id' => $course->id,
        ]);

        $this->actingAs($user)
            ->postJson('/api/chatbot/message', [
                'message' => 'Try injecting course conversation into general bot',
                'conversation_id' => $courseConv->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Invalid conversation session for general chatbot. Course sessions cannot be used with the general bot.');
    }

    public function test_course_chatbot_rejects_general_conversation_session(): void
    {
        Setting::updateOrCreate(['name' => 'chatbot_enabled'], ['value' => '1', 'type' => 'boolean']);
        $user = User::factory()->create();
        $course = Course::factory()->create([
            'chatbot_enabled' => true,
            'ai_knowledge_content' => 'Sample content',
        ]);

        // Enroll user
        $chapter = \App\Models\Course\CourseChapter\CourseChapter::factory()->create(['course_id' => $course->id]);
        \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::factory()->create(['course_chapter_id' => $chapter->id]);
        $order = \App\Models\Order::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'payment_method' => 'wallet',
            'total_price' => 100,
            'final_price' => 100,
            'order_number' => 'ORD-TEST-ISO-' . $user->id,
        ]);
        \App\Models\OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $course->id,
            'price' => 100,
            'tax_price' => 0,
        ]);

        $generalConv = \App\Models\ChatbotConversation::create([
            'user_id' => $user->id,
            'title' => 'General Session',
            'type' => 'general',
            'course_id' => null,
        ]);

        $this->actingAs($user)
            ->postJson('/api/chatbot/course-message', [
                'course_id' => $course->id,
                'message' => 'Try injecting general conversation into course bot',
                'conversation_id' => $generalConv->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Invalid conversation session for this course. Cross-course or general sessions are strictly rejected.');
    }

    public function test_course_chatbot_rejects_cross_course_conversation_session(): void
    {
        Setting::updateOrCreate(['name' => 'chatbot_enabled'], ['value' => '1', 'type' => 'boolean']);
        $user = User::factory()->create();
        $courseA = Course::factory()->create(['chatbot_enabled' => true, 'ai_knowledge_content' => 'Course A content']);
        $courseB = Course::factory()->create(['chatbot_enabled' => true, 'ai_knowledge_content' => 'Course B content']);

        // Enroll in course B
        $chapter = \App\Models\Course\CourseChapter\CourseChapter::factory()->create(['course_id' => $courseB->id]);
        \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::factory()->create(['course_chapter_id' => $chapter->id]);
        $order = \App\Models\Order::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'payment_method' => 'wallet',
            'total_price' => 100,
            'final_price' => 100,
            'order_number' => 'ORD-TEST-ISO-B-' . $user->id,
        ]);
        \App\Models\OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $courseB->id,
            'price' => 100,
            'tax_price' => 0,
        ]);

        // Conversation belonging to course A
        $convCourseA = \App\Models\ChatbotConversation::create([
            'user_id' => $user->id,
            'title' => 'Course A Session',
            'type' => 'course',
            'course_id' => $courseA->id,
        ]);

        // Attempt to send message to course B with course A's conversation ID
        $this->actingAs($user)
            ->postJson('/api/chatbot/course-message', [
                'course_id' => $courseB->id,
                'message' => 'Access Course B with Course A session',
                'conversation_id' => $convCourseA->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Invalid conversation session for this course. Cross-course or general sessions are strictly rejected.');
    }

    public function test_embedding_service_strict_scope_isolation(): void
    {
        $courseA = Course::factory()->create();
        $courseB = Course::factory()->create();

        // Create vector chunks
        \App\Models\ChatbotVectorChunk::create([
            'bot_type' => 'course',
            'course_id' => $courseA->id,
            'title' => 'Course A Secret',
            'chunk_text' => 'SECRET_TOKEN_COURSE_A_9999 is only for Course A.',
            'chunk_index' => 0,
            'is_active' => true,
        ]);

        \App\Models\ChatbotVectorChunk::create([
            'bot_type' => 'course',
            'course_id' => $courseB->id,
            'title' => 'Course B Secret',
            'chunk_text' => 'SECRET_TOKEN_COURSE_B_8888 is only for Course B.',
            'chunk_index' => 0,
            'is_active' => true,
        ]);

        \App\Models\ChatbotVectorChunk::create([
            'bot_type' => 'visitor',
            'course_id' => null,
            'title' => 'Public Platform Info',
            'chunk_text' => 'Skillso offers a variety of public plans and subscriptions.',
            'chunk_index' => 0,
            'is_active' => true,
        ]);

        $embedder = new \App\Services\EmbeddingService();

        // 1. Search as visitor — must NEVER return course A or B
        $visitorChunks = $embedder->searchSimilarChunks('SECRET_TOKEN', 'visitor', null, 5);
        $this->assertEmpty($visitorChunks);

        // 2. Search for Course A — must ONLY return Course A chunk
        $courseAChunks = $embedder->searchSimilarChunks('SECRET_TOKEN', 'course', $courseA->id, 5);
        $this->assertCount(1, $courseAChunks);
        $this->assertEquals('Course A Secret', $courseAChunks[0]['title']);

        // 3. Search for Course B — must ONLY return Course B chunk
        $courseBChunks = $embedder->searchSimilarChunks('SECRET_TOKEN', 'course', $courseB->id, 5);
        $this->assertCount(1, $courseBChunks);
        $this->assertEquals('Course B Secret', $courseBChunks[0]['title']);

        // 4. Search for Course C (not existing) — must return 0
        $courseCChunks = $embedder->searchSimilarChunks('SECRET_TOKEN', 'course', 999999, 5);
        $this->assertEmpty($courseCChunks);
    }

    public function test_general_bot_rejects_course_lesson_question_and_redirects(): void
    {
        Setting::updateOrCreate(['name' => 'chatbot_enabled'], ['value' => '1', 'type' => 'boolean']);

        // 1. English lesson inquiry
        $resEn = $this->postJson('/api/chatbot/message', [
            'message' => 'What did the instructor explain about recursion in lesson 4?',
        ]);

        $resEn->assertStatus(200);
        $resEn->assertJsonPath('status', true);
        $this->assertStringContainsString('Course Assistant below the course video', (string) $resEn->json('data.reply'));
        $this->assertEmpty($resEn->json('data.citations'));

        // 2. Arabic lesson inquiry
        $resAr = $this->postJson('/api/chatbot/message', [
            'message' => 'اشرح لي محتوى الدرس الرابع وماذا قال المحاضر فيه؟',
        ]);

        $resAr->assertStatus(200);
        $resAr->assertJsonPath('status', true);
        $this->assertStringContainsString('مساعد الكورس', (string) $resAr->json('data.reply'));
        $this->assertStringContainsString('أسفل فيديو الشرح', (string) $resAr->json('data.reply'));
        $this->assertEmpty($resAr->json('data.citations'));

        // 3. Summarize lesson inquiry
        $resSummary = $this->postJson('/api/chatbot/message', [
            'message' => 'Summarize lesson 3 of this course please.',
        ]);

        $resSummary->assertStatus(200);
        $this->assertStringContainsString('Course Assistant below the course video', (string) $resSummary->json('data.reply'));
    }

    public function test_general_bot_answers_general_platform_question(): void
    {
        Setting::updateOrCreate(['name' => 'chatbot_enabled'], ['value' => '1', 'type' => 'boolean']);

        $res = $this->postJson('/api/chatbot/message', [
            'message' => 'What subscription plans does Skillso offer?',
        ]);

        $res->assertStatus(200);
        $res->assertJsonPath('status', true);
        $this->assertNotEmpty($res->json('data.reply'));
    }

    public function test_adversarial_cross_course_content_isolation(): void
    {
        Setting::updateOrCreate(['name' => 'chatbot_enabled'], ['value' => '1', 'type' => 'boolean']);

        // Course A with SECRET_A_123
        $courseA = Course::factory()->create([
            'title' => 'Course A',
            'chatbot_enabled' => true,
            'ai_knowledge_content' => 'The secret key for Course A is SECRET_A_123.',
        ]);
        $chapterA = \App\Models\Course\CourseChapter\CourseChapter::factory()->create(['course_id' => $courseA->id]);
        \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::factory()->create(['course_chapter_id' => $chapterA->id]);

        // Course B with SECRET_B_456
        $courseB = Course::factory()->create([
            'title' => 'Course B',
            'chatbot_enabled' => true,
            'ai_knowledge_content' => 'The secret key for Course B is SECRET_B_456.',
        ]);
        $chapterB = \App\Models\Course\CourseChapter\CourseChapter::factory()->create(['course_id' => $courseB->id]);
        \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::factory()->create(['course_chapter_id' => $chapterB->id]);

        // Student enrolled in Course A ONLY
        $studentA = User::factory()->create();
        $order = \App\Models\Order::create([
            'user_id' => $studentA->id,
            'status' => 'completed',
            'payment_method' => 'wallet',
            'total_price' => 100,
            'final_price' => 100,
            'order_number' => 'ORD-TEST-ISO-A-' . $studentA->id,
        ]);
        \App\Models\OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $courseA->id,
            'price' => 100,
            'tax_price' => 0,
        ]);

        // 1. Student A queries Course A -> Allowed
        $resA = $this->actingAs($studentA)->postJson('/api/chatbot/course-message', [
            'course_id' => $courseA->id,
            'message' => 'What is the secret key for Course A?',
        ]);
        $resA->assertStatus(200);

        // 2. Student A attempts to query Course B -> Rejected 403 (unauthorized)
        $resB = $this->actingAs($studentA)->postJson('/api/chatbot/course-message', [
            'course_id' => $courseB->id,
            'message' => 'What is the secret key for Course B?',
        ]);
        $resB->assertStatus(403);
        $resB->assertJsonPath('status', false);
        $resB->assertJsonPath('message', 'You must be enrolled in this course to use the assistant');

        // 3. General bot inquiry for secret key in lesson -> Redirected / No course leakage
        $resGen = $this->postJson('/api/chatbot/message', [
            'message' => 'What was explained about SECRET_A_123 in lesson 2?',
        ]);
        $resGen->assertStatus(200);
        $this->assertStringContainsString('Course Assistant below the course video', (string) $resGen->json('data.reply'));
    }
}

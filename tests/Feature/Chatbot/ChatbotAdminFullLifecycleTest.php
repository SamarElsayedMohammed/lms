<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbot;

use App\Models\ChatbotConversation;
use App\Models\ChatbotFaq;
use App\Models\ChatbotKnowledgeBase;
use App\Models\ChatbotMessage;
use App\Models\ChatbotVectorChunk;
use App\Models\Course\Course;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCourseProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatbotAdminFullLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // Fake AI response
        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Mock AI generated response']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('Super Admin');
    }

    public function test_admin_settings_key_masking_and_placeholder_protection(): void
    {
        Setting::updateOrCreate(
            ['name' => 'openai_api_key'],
            ['value' => 'sk-original-secret-production-key-998877', 'type' => 'text']
        );

        // 1. Fetch settings as admin
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/chatbot/settings');

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.openai_api_key_configured'));
        $this->assertEquals('••••••••', $response->json('data.openai_api_key'));
        $this->assertStringNotContainsString('sk-original', $response->getContent());

        // 2. Submit update with placeholder mask
        $updateResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson('/api/admin/chatbot/settings', [
                'chatbot_name' => 'Updated Skillso Bot',
                'openai_api_key' => '••••••••',
            ]);

        $updateResponse->assertStatus(200);
        // Verify real key was not overwritten in DB
        $dbKey = Setting::where('name', 'openai_api_key')->value('value');
        $this->assertEquals('sk-original-secret-production-key-998877', $dbKey);

        // 3. Submit genuine new key
        $newKeyResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson('/api/admin/chatbot/settings', [
                'openai_api_key' => 'sk-brand-new-rotated-key-112233',
            ]);

        $newKeyResponse->assertStatus(200);
        $dbKeyRotated = Setting::where('name', 'openai_api_key')->value('value');
        $this->assertEquals('sk-brand-new-rotated-key-112233', $dbKeyRotated);
    }

    public function test_faq_crud_soft_delete_and_restore(): void
    {
        // 1. Create FAQ
        $createRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/admin/chatbot/faqs', [
                'question' => 'كيف أسجل في دورة؟',
                'answer' => 'يمكنك التسجيل عبر زر اشترك الآن.',
                'order' => 1,
            ]);

        $createRes->assertStatus(201);
        $faqId = $createRes->json('data.id');

        // 2. Delete FAQ (Soft Delete)
        $delRes = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/admin/chatbot/faqs/{$faqId}");

        $delRes->assertStatus(200);
        $this->assertSoftDeleted('chatbot_faqs', ['id' => $faqId]);

        // 3. Restore FAQ
        $restoreRes = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/admin/chatbot/faqs/{$faqId}/restore");

        $restoreRes->assertStatus(200);
        $this->assertDatabaseHas('chatbot_faqs', [
            'id' => $faqId,
            'deleted_at' => null,
        ]);
    }

    public function test_knowledge_base_crud_chunk_cascade_and_toggle_sync(): void
    {
        // 1. Create Knowledge Base Item
        $createRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/admin/chatbot/knowledge', [
                'title' => 'شروط الاستخدام والسياسات',
                'content' => 'سكيلزو هي منصة تعليمية رائدة تقدم دورات مهنية متخصصة في مختلف المجالات التقنية والإدارية.',
                'target_audience' => 'visitor',
                'is_active' => true,
            ]);

        $createRes->assertStatus(201);
        $kbId = $createRes->json('data.id');

        // Chunks should exist
        $this->assertDatabaseHas('chatbot_vector_chunks', [
            'knowledge_base_id' => $kbId,
            'bot_type' => 'visitor',
            'is_active' => 1,
        ]);

        // 2. Toggle active state to inactive
        $toggleRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/admin/chatbot/knowledge/{$kbId}/toggle");

        $toggleRes->assertStatus(200);
        $this->assertDatabaseHas('chatbot_knowledge_bases', [
            'id' => $kbId,
            'is_active' => 0,
        ]);
        $this->assertDatabaseHas('chatbot_vector_chunks', [
            'knowledge_base_id' => $kbId,
            'is_active' => 0,
        ]);

        // 3. Delete knowledge base -> Vector chunks must be cascade deleted
        $deleteRes = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/admin/chatbot/knowledge/{$kbId}");

        $deleteRes->assertStatus(200);
        $this->assertDatabaseMissing('chatbot_knowledge_bases', ['id' => $kbId]);
        $this->assertDatabaseMissing('chatbot_vector_chunks', ['knowledge_base_id' => $kbId]);
    }

    public function test_admin_conversation_transcript_expansion(): void
    {
        $student = User::factory()->create([
            'name' => 'أحمد محمود',
            'email' => 'ahmed@example.com',
            'mobile' => '01012345678',
        ]);

        $course = Course::factory()->create(['title' => 'كورس الذكاء الاصطناعي']);

        $conversation = ChatbotConversation::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'title' => 'استفسار عن المحاضرة الأولى',
            'type' => 'course',
            'last_message_at' => now(),
        ]);

        ChatbotMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'course_id' => $course->id,
            'message' => 'ما هي متطلبات المحاضرة الأولى؟',
            'reply' => 'متطلبات المحاضرة الأولى هي تثبيت بيئة بايثون.',
            'type' => 'ai_course',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/admin/chatbot/conversations/{$conversation->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals('أحمد محمود', $data['user_name']);
        $this->assertEquals('ahmed@example.com', $data['user_email']);
        $this->assertEquals('01012345678', $data['user_phone']);

        // Verify transcript messages has 2 items: 1 user, 1 bot
        $messages = $data['messages'];
        $this->assertCount(2, $messages);
        $this->assertEquals('user', $messages[0]['sender']);
        $this->assertEquals('ما هي متطلبات المحاضرة الأولى؟', $messages[0]['message']);
        $this->assertEquals('bot', $messages[1]['sender']);
        $this->assertEquals('متطلبات المحاضرة الأولى هي تثبيت بيئة بايثون.', $messages[1]['message']);
    }

    public function test_unauthenticated_debug_route_is_removed_or_not_found(): void
    {
        $response = $this->getJson('/api/chatbot/debug');
        $response->assertStatus(404);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatbotFaq;
use App\Models\ChatbotKnowledgeBase;
use App\Models\ChatbotMessage;
use App\Models\ChatbotConversation;
use App\Models\Course\Course;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ChatBotService
{
    /**
     * Get FAQ answer directly — no AI involved
     */
    public function getFaqAnswer(int $faqId): ?array
    {
        $faq = ChatbotFaq::active()->find($faqId);

        if (!$faq) {
            return null;
        }

        $data = [
            'question' => $faq->question,
            'answer' => $faq->answer,
            'type' => 'faq',
        ];

        // Log the interaction
        ChatbotMessage::create([
            'user_id' => Auth::id(),
            'session_id' => request()->header('X-Chat-Session-ID'),
            'message' => $faq->question,
            'reply' => $faq->answer,
            'type' => 'faq',
        ]);

        return $data;
    }

    /**
     * Process a free-text message using AI
     */
    public function processMessage(string $message, ?int $conversationId = null): array
    {
        $settings = $this->getChatbotSettings();
        $knowledgeContext = $this->buildKnowledgeContext();
        $systemPrompt = $this->buildSystemPrompt($settings, $knowledgeContext);

        try {
            $reply = $this->callGeminiApi($systemPrompt, $message, (int) ($settings['chatbot_max_tokens'] ?? 500));

            // Manage Conversation
            $userId = Auth::id();
            if ($userId) {
                if ($conversationId) {
                    $conversation = ChatbotConversation::where('user_id', $userId)->find($conversationId);
                }

                if (empty($conversation)) {
                    $conversation = ChatbotConversation::create([
                        'user_id' => $userId,
                        'title' => Str::limit($message, 50),
                        'type' => 'general',
                    ]);
                }

                $conversation->update(['last_message_at' => now()]);
            }

            // Log the interaction
            ChatbotMessage::create([
                'user_id' => $userId,
                'conversation_id' => isset($conversation) ? $conversation->id : null,
                'session_id' => request()->header('X-Chat-Session-ID'),
                'message' => $message,
                'reply' => $reply,
                'type' => 'ai_general',
            ]);

            return [
                'reply' => $reply,
                'type' => 'ai',
                'conversation_id' => isset($conversation) ? $conversation->id : null,
            ];
        } catch (\Throwable $e) {
            Log::error('ChatBot AI Error: ' . $e->getMessage(), [
                'message' => $message,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'reply' => 'عذراً، حصل مشكلة تقنية. حاول تاني أو تواصل مع الدعم الفني. 🙏',
                'type' => 'error',
            ];
        }
    }

    /**
     * Process a message for a course-specific chatbot
     * Uses the course's own knowledge base file
     */
    public function processCourseMessage(string $message, Course $course, ?int $conversationId = null): array
    {
        if (empty($course->ai_knowledge_content)) {
            return [
                'reply' => 'عذراً، لسه مفيش محتوى معرفي متاح للكورس ده. تواصل مع المدرب أو الدعم الفني. 🙏',
                'type' => 'error',
            ];
        }

        $settings = $this->getChatbotSettings();
        $botName = $settings['chatbot_name'] ?? 'سكيلزوا';

        $systemPrompt = "أنت {$botName}، مساعد ذكي لكورس \"{$course->title}\" على منصة Skillso التعليمية.\n\n";
        $systemPrompt .= "=== محتوى الكورس ===\n";
        $systemPrompt .= $course->ai_knowledge_content . "\n\n";
        $systemPrompt .= "=== تعليمات مهمة ===\n";
        $systemPrompt .= "- أجب فقط بناءً على محتوى الكورس المتاح أعلاه\n";
        $systemPrompt .= "- لو السؤال خارج نطاق الكورس، اعتذر بلطف وقول إنك متخصص في كورس {$course->title} فقط\n";
        $systemPrompt .= "- الرد يكون مختصر وواضح ومفيد للطالب\n";
        $systemPrompt .= "- استخدم الإيموجي بشكل مناسب\n";

        try {
            $maxTokens = (int) ($settings['chatbot_max_tokens'] ?? 500);
            $reply = $this->callGeminiApi($systemPrompt, $message, $maxTokens);

            // Manage Conversation
            $userId = Auth::id();
            if ($userId) {
                if ($conversationId) {
                    $conversation = ChatbotConversation::where('user_id', $userId)->where('course_id', $course->id)->find($conversationId);
                }

                if (empty($conversation)) {
                    $conversation = ChatbotConversation::create([
                        'user_id' => $userId,
                        'title' => Str::limit($message, 50),
                        'type' => 'course',
                        'course_id' => $course->id,
                    ]);
                }

                $conversation->update(['last_message_at' => now()]);
            }

            // Log the interaction
            ChatbotMessage::create([
                'user_id' => $userId,
                'conversation_id' => isset($conversation) ? $conversation->id : null,
                'message' => $message,
                'reply' => $reply,
                'type' => 'ai_course',
                'course_id' => $course->id,
            ]);

            return [
                'reply' => $reply,
                'type' => 'ai',
                'course_id' => $course->id,
            ];
        } catch (\Throwable $e) {
            Log::error('Course ChatBot AI Error: ' . $e->getMessage(), [
                'message' => $message,
                'course_id' => $course->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'reply' => 'عذراً، حصل مشكلة تقنية. حاول تاني أو تواصل مع الدعم الفني. 🙏',
                'type' => 'error',
            ];
        }
    }

    /**
     * Get chatbot settings from the settings table
     */
    public function getChatbotSettings(): array
    {
        return CachingService::getSystemSettings([
            'chatbot_enabled',
            'chatbot_name',
            'chatbot_welcome_message',
            'chatbot_system_prompt',
            'chatbot_max_tokens',
            'chatbot_position',
            'chatbot_icon',
        ]);
    }

    /**
     * Build knowledge context from all active knowledge base entries
     */
    private function buildKnowledgeContext(): string
    {
        $entries = ChatbotKnowledgeBase::active()->get();

        if ($entries->isEmpty()) {
            return '';
        }

        $context = "=== قاعدة المعرفة ===\n\n";

        foreach ($entries as $entry) {
            $context .= "--- {$entry->title} ---\n";
            $context .= $entry->content . "\n\n";
        }

        return $context;
    }

    /**
     * Build the full system prompt for the AI
     */
    private function buildSystemPrompt(array $settings, string $knowledgeContext): string
    {
        $botName = $settings['chatbot_name'] ?? 'سكيلزوا';
        $adminPrompt = $settings['chatbot_system_prompt'] ?? '';

        $prompt = "أنت {$botName}، مساعد ذكي لمنصة Skillso التعليمية.\n\n";

        if (!empty($adminPrompt)) {
            $prompt .= $adminPrompt . "\n\n";
        }

        if (!empty($knowledgeContext)) {
            $prompt .= $knowledgeContext . "\n";
        }

        $prompt .= "=== تعليمات مهمة ===\n";
        $prompt .= "- أجب فقط بناءً على قاعدة المعرفة المتاحة أعلاه\n";
        $prompt .= "- لو السؤال خارج نطاق قاعدة المعرفة، اعتذر بلطف واقترح التواصل مع الدعم الفني\n";
        $prompt .= "- الرد يكون مختصر وواضح\n";
        $prompt .= "- استخدم الإيموجي بشكل مناسب\n";

        return $prompt;
    }

    /**
     * Call Gemini API
     */
    private function callGeminiApi(string $systemPrompt, string $userMessage, int $maxTokens = 500): string
    {
        $apiKey = config('services.gemini.api_key');
        $model = config('services.gemini.model', 'gemini-2.0-flash');

        if (empty($apiKey)) {
            throw new \RuntimeException('Gemini API key is not configured. Set GEMINI_API_KEY in .env');
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $response = Http::timeout(30)->post($url, [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userMessage],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature' => 0.7,
            ],
        ]);

        if (!$response->successful()) {
            Log::error('Gemini API Error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Gemini API returned error: ' . $response->status());
        }

        $data = $response->json();

        // Extract text from Gemini response
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (empty($text)) {
            throw new \RuntimeException('Empty response from Gemini API');
        }

        return trim($text);
    }
}

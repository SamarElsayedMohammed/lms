<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ChatbotFaq;
use App\Models\ChatbotConversation;
use App\Models\Course\Course;
use App\Services\CachingService;
use App\Services\ChatBotService;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ChatbotApiController extends Controller
{
    /**
     * Get chatbot configuration for the frontend
     * Returns: bot name, welcome message, position, icon, FAQ buttons
     */
    public function getConfig(): JsonResponse
    {
        $settings = CachingService::getSystemSettings([
            'chatbot_enabled',
            'chatbot_name',
            'chatbot_welcome_message',
            'chatbot_position',
            'chatbot_icon',
        ]);

        // Check if chatbot is enabled
        if (empty($settings['chatbot_enabled']) || $settings['chatbot_enabled'] === '0') {
            return response()->json([
                'status' => true,
                'data' => [
                    'enabled' => false,
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        // Get active FAQ buttons
        $faqs = ChatbotFaq::active()
            ->ordered()
            ->select('id', 'question', 'category')
            ->get();

        // Build icon URL
        $iconUrl = null;
        if (!empty($settings['chatbot_icon'])) {
            $iconUrl = FileService::getFileUrl($settings['chatbot_icon']);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'enabled' => true,
                'name' => $settings['chatbot_name'] ?? 'سكيلزوا',
                'welcome_message' => $settings['chatbot_welcome_message'] ?? '',
                'position' => $settings['chatbot_position'] ?? 'bottom-right',
                'icon' => $iconUrl,
                'faqs' => $faqs,
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get chatbot configuration for a specific course
     * Uses course-specific settings if available, falls back to global settings
     */
    public function getCourseConfig(int $courseId): JsonResponse
    {
        $course = Course::find($courseId);
        
        if (!$course || empty($course->ai_knowledge_content) || !$course->chatbot_enabled) {
            return response()->json([
                'status' => true,
                'data' => [
                    'enabled' => false,
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        $settings = CachingService::getSystemSettings([
            'chatbot_name',
            'chatbot_welcome_message',
            'chatbot_position',
            'chatbot_icon',
        ]);

        // Build icon URL
        $iconUrl = null;
        if (!empty($settings['chatbot_icon'])) {
            $iconUrl = FileService::getFileUrl($settings['chatbot_icon']);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'enabled' => true,
                'name' => $course->chatbot_name ?: ($settings['chatbot_name'] ?? 'سكيلزوا'),
                'welcome_message' => $course->chatbot_welcome_message ?: ($settings['chatbot_welcome_message'] ?? ''),
                'position' => $settings['chatbot_position'] ?? 'bottom-right',
                'icon' => $iconUrl,
                'faqs' => [], // Courses typically don't have general FAQs
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get a direct FAQ answer — no AI involved
     * User clicked a FAQ button
     */
    public function getFaqAnswer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'faq_id' => 'required|integer|exists:chatbot_faqs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        $service = new ChatBotService();
        $result = $service->getFaqAnswer((int) $request->input('faq_id'));

        if (!$result) {
            return response()->json([
                'status' => false,
                'message' => __('FAQ not found or inactive'),
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json([
            'status' => true,
            'data' => $result,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Send a free-text message — AI responds using knowledge base
     */
    public function sendMessage(Request $request): JsonResponse
    {
        // Check if chatbot is enabled
        $enabled = CachingService::getSystemSettings('chatbot_enabled');
        if (empty($enabled) || $enabled === '0') {
            return response()->json([
                'status' => false,
                'message' => __('Chatbot is currently disabled'),
            ], 403, [], JSON_UNESCAPED_UNICODE);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:1|max:1000',
            'conversation_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        $service = new ChatBotService();
        $result = $service->processMessage(
            $request->input('message'),
            $request->input('conversation_id') ? (int) $request->input('conversation_id') : null
        );

        return response()->json([
            'status' => true,
            'data' => $result,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Send a message to a course-specific chatbot
     * Requires authentication — subscriber only
     */
    public function sendCourseMessage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|integer|exists:courses,id',
            'message' => 'required|string|min:1|max:1000',
            'conversation_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        $course = Course::find($request->input('course_id'));

        if (!$course) {
            return response()->json([
                'status' => false,
                'message' => __('Course not found'),
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }

        // Check if course has AI knowledge content
        if (empty($course->getRawOriginal('ai_knowledge_content'))) {
            return response()->json([
                'status' => false,
                'message' => __('AI assistant is not available for this course'),
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }

        $service = new ChatBotService();
        $result = $service->processCourseMessage(
            $request->input('message'),
            $course,
            $request->input('conversation_id') ? (int) $request->input('conversation_id') : null
        );

        return response()->json([
            'status' => true,
            'data' => $result,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get user's past conversations
     */
    public function getConversations(Request $request): JsonResponse
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
        }

        $type = $request->input('type'); // general or course
        $courseId = $request->input('course_id');

        $conversations = ChatbotConversation::where('user_id', $userId)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->orderBy('last_message_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $conversations,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get messages for a specific conversation
     */
    public function getConversationMessages(int $id): JsonResponse
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
        }

        $conversation = ChatbotConversation::where('user_id', $userId)->find($id);

        if (!$conversation) {
            return response()->json(['status' => false, 'message' => 'Conversation not found'], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $conversation->messages,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Diagnostic debug endpoint for testing AI settings on the staging server
     */
    public function debug(): JsonResponse
    {
        $provider = env('AI_PROVIDER', 'not_set');
        $openRouterKey = env('OPENROUTER_API_KEY');
        $openRouterModel = env('OPENROUTER_MODEL');
        $geminiKey = config('services.gemini.api_key');

        // Mask keys for safety
        $maskedOpenRouterKey = $openRouterKey ? substr($openRouterKey, 0, 10) . '...' : 'null';
        $maskedGeminiKey = $geminiKey ? substr($geminiKey, 0, 10) . '...' : 'null';

        $debugData = [
            'env' => [
                'AI_PROVIDER' => $provider,
                'OPENROUTER_API_KEY' => $maskedOpenRouterKey,
                'OPENROUTER_MODEL' => $openRouterModel,
                'GEMINI_API_KEY' => $maskedGeminiKey,
            ],
            'test_connection' => null,
        ];

        try {
            $service = new \App\Services\ChatBotService();
            $reflection = new \ReflectionMethod($service, 'callAiApi');
            $reflection->setAccessible(true);
            $reply = $reflection->invoke($service, 'You are a debug assistant.', 'Hello', 10);

            $debugData['test_connection'] = [
                'status' => 'success',
                'reply' => $reply,
            ];
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $debugData['test_connection'] = [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }

        return response()->json($debugData);
    }
}

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
}

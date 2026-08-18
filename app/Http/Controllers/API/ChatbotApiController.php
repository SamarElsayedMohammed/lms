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
     * Get chatbot configuration for the visitor widget
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

        $enabled = !empty($settings['chatbot_enabled']) && $settings['chatbot_enabled'] !== '0' && $settings['chatbot_enabled'] !== 'false';

        if (!$enabled) {
            return response()->json([
                'status' => true,
                'data' => [
                    'enabled' => false,
                    'available' => false,
                    'reason_code' => 'global_bot_disabled',
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        $faqs = ChatbotFaq::active()
            ->ordered()
            ->select('id', 'question', 'category')
            ->get();

        $iconUrl = null;
        if (!empty($settings['chatbot_icon'])) {
            $iconUrl = FileService::getFileUrl($settings['chatbot_icon']);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'enabled' => true,
                'available' => true,
                'reason_code' => null,
                'name' => $settings['chatbot_name'] ?? 'سكيلزوا',
                'welcome_message' => $settings['chatbot_welcome_message'] ?? '',
                'position' => $settings['chatbot_position'] ?? 'bottom-right',
                'icon' => $iconUrl,
                'faqs' => $faqs,
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Authoritative runtime availability decision for subscriber course assistant
     */
    public function getCourseConfig(int $courseId): JsonResponse
    {
        $course = Course::find($courseId);
        
        if (!$course) {
            return response()->json([
                'status' => true,
                'data' => [
                    'enabled' => false,
                    'available' => false,
                    'reason_code' => 'course_not_found',
                    'course_id' => $courseId,
                    'can_send_message' => false,
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        // Global subscriber bot setting check
        $globalEnabled = CachingService::getSystemSettings('chatbot_enabled');
        $isGlobalEnabled = !empty($globalEnabled) && $globalEnabled !== '0' && $globalEnabled !== 'false';

        if (!$isGlobalEnabled) {
            return response()->json([
                'status' => true,
                'data' => [
                    'enabled' => false,
                    'available' => false,
                    'reason_code' => 'subscriber_bot_disabled',
                    'course_id' => $courseId,
                    'course_enabled' => (bool) $course->chatbot_enabled,
                    'subscriber_bot_enabled' => false,
                    'can_send_message' => false,
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        // Course assistant enabled toggle check
        if (!$course->chatbot_enabled) {
            return response()->json([
                'status' => true,
                'data' => [
                    'enabled' => false,
                    'available' => false,
                    'reason_code' => 'course_bot_disabled',
                    'course_id' => $courseId,
                    'course_enabled' => false,
                    'subscriber_bot_enabled' => true,
                    'can_send_message' => false,
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        // Student authorization & canonical entitlement check
        $user = Auth::guard('sanctum')->user() ?: Auth::user();
        $studentAuthorized = $course->isUserEntitled($user);

        // Knowledge status decision
        $knowledgeStatus = 'ready';
        if (!empty($course->ai_processing_status)) {
            $knowledgeStatus = $course->ai_processing_status;
        } elseif (empty($course->ai_knowledge_content) && empty($course->ai_knowledge_file)) {
            $knowledgeStatus = 'not_configured';
        }

        // Reason code evaluation
        $reasonCode = null;
        $available = true;

        if (!$studentAuthorized) {
            $reasonCode = $user ? 'enrollment_required' : 'access_required';
            $available = false;
        } elseif ($knowledgeStatus === 'processing' || $knowledgeStatus === 'queued') {
            $reasonCode = 'knowledge_processing';
            $available = false;
        } elseif ($knowledgeStatus === 'failed') {
            $reasonCode = 'knowledge_failed';
            $available = false;
        } elseif ($knowledgeStatus === 'not_configured' && empty($course->ai_knowledge_content)) {
            $reasonCode = 'knowledge_empty';
            $available = false;
        }

        $settings = CachingService::getSystemSettings([
            'chatbot_name',
            'chatbot_welcome_message',
            'chatbot_position',
            'chatbot_icon',
        ]);

        $iconUrl = null;
        if (!empty($settings['chatbot_icon'])) {
            $iconUrl = FileService::getFileUrl($settings['chatbot_icon']);
        }

        $canSendMessage = $available && $studentAuthorized;

        return response()->json([
            'status' => true,
            'data' => [
                'enabled' => $available,
                'available' => $available,
                'reason_code' => $reasonCode,
                'course_id' => $course->id,
                'course_enabled' => true,
                'subscriber_bot_enabled' => true,
                'student_authorized' => $studentAuthorized,
                'knowledge_status' => $knowledgeStatus,
                'can_send_message' => $canSendMessage,
                'name' => $course->chatbot_name ?: ($settings['chatbot_name'] ?? 'سكيلزوا'),
                'welcome_message' => $course->chatbot_welcome_message ?: ($settings['chatbot_welcome_message'] ?? ''),
                'position' => $settings['chatbot_position'] ?? 'bottom-right',
                'icon' => $iconUrl,
                'faqs' => [],
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get direct FAQ answer
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
     * Send visitor message
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $enabled = CachingService::getSystemSettings('chatbot_enabled');
        if (empty($enabled) || $enabled === '0' || $enabled === 'false') {
            return response()->json([
                'status' => false,
                'message' => __('Chatbot is currently disabled'),
            ], 403, [], JSON_UNESCAPED_UNICODE);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:1|max:1500',
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
     * Send course message for subscriber
     */
    public function sendCourseMessage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|integer|exists:courses,id',
            'message' => 'required|string|min:1|max:1500',
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

        if (!$course->chatbot_enabled) {
            return response()->json([
                'status' => false,
                'message' => __('AI assistant is not available for this course'),
            ], 403, [], JSON_UNESCAPED_UNICODE);
        }

        $user = Auth::guard('sanctum')->user() ?: Auth::user();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => __('Unauthenticated'),
            ], 401, [], JSON_UNESCAPED_UNICODE);
        }

        // Verify active course entitlement
        $isAuthorized = $course->isUserEntitled($user);

        if (!$isAuthorized) {
            return response()->json([
                'status' => false,
                'message' => __('You must be enrolled in this course to use the assistant'),
            ], 403, [], JSON_UNESCAPED_UNICODE);
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

        $type = $request->input('type');
        $courseId = $request->input('course_id');

        $conversations = ChatbotConversation::where('user_id', $userId)
            ->withCount('messages')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->orderBy('last_message_at', 'desc')
            ->limit(50)
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

        $messages = [];
        foreach ($conversation->messages as $msg) {
            $messages[] = [
                'id' => $msg->id . '_user',
                'conversation_id' => $id,
                'sender' => 'user',
                'message' => $msg->message,
                'created_at' => $msg->created_at,
            ];
            $messages[] = [
                'id' => $msg->id . '_bot',
                'conversation_id' => $id,
                'sender' => 'bot',
                'message' => $msg->reply,
                'created_at' => $msg->created_at,
            ];
        }

        return response()->json([
            'status' => true,
            'data' => $messages,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}

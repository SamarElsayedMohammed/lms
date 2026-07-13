<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\ChatbotFaq;
use App\Models\ChatbotKnowledgeBase;
use App\Models\ChatbotMessage;
use App\Models\Setting;
use App\Services\ChatBotService;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ChatbotAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    // ==========================================
    // Settings
    // ==========================================

    /**
     * Get all chatbot settings
     */
    public function getSettings(): JsonResponse
    {
        $this->ensureAdmin();

        $settingKeys = [
            'chatbot_enabled',
            'chatbot_name',
            'chatbot_welcome_message',
            'chatbot_system_prompt',
            'chatbot_subscriber_system_prompt',
            'chatbot_max_tokens',
            'chatbot_position',
            'chatbot_icon',
            'openai_api_key',
        ];

        $settings = [];
        foreach ($settingKeys as $key) {
            $setting = Setting::where('name', $key)->first();
            $val = $setting?->value;
            if ($key === 'openai_api_key' && !empty($val) && strlen($val) > 10) {
                $val = substr($val, 0, 10) . '...';
            }
            $settings[$key] = $val;
        }

        return $this->jsonSuccess(__('Chatbot settings retrieved'), $settings);
    }

    /**
     * Update chatbot settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'chatbot_enabled' => 'nullable|boolean',
            'chatbot_name' => 'nullable|string|max:100',
            'chatbot_welcome_message' => 'nullable|string|max:1000',
            'chatbot_system_prompt' => 'nullable|string|max:5000',
            'chatbot_subscriber_system_prompt' => 'nullable|string|max:5000',
            'chatbot_max_tokens' => 'nullable|integer|min:100|max:2000',
            'chatbot_position' => 'nullable|string|in:bottom-right,bottom-left,top-right,top-left',
            'chatbot_icon' => 'nullable|image|mimes:jpg,jpeg,png,svg,webp,gif|max:2048',
            'openai_api_key' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $settingKeys = [
                'chatbot_enabled',
                'chatbot_name',
                'chatbot_welcome_message',
                'chatbot_system_prompt',
                'chatbot_subscriber_system_prompt',
                'chatbot_max_tokens',
                'chatbot_position',
                'openai_api_key',
            ];

            foreach ($settingKeys as $key) {
                if ($request->has($key)) {
                    $value = $key === 'chatbot_enabled'
                        ? ($request->boolean($key) ? '1' : '0')
                        : (string) $request->input($key);

                    Setting::updateOrCreate(
                        ['name' => $key],
                        ['value' => $value, 'type' => $key === 'chatbot_enabled' ? 'boolean' : 'text']
                    );
                }
            }

            // Handle icon upload
            if ($request->hasFile('chatbot_icon')) {
                $oldIcon = Setting::where('name', 'chatbot_icon')->value('value');
                $iconPath = FileService::compressAndReplace(
                    $request->file('chatbot_icon'),
                    'chatbot',
                    $oldIcon
                );

                Setting::updateOrCreate(
                    ['name' => 'chatbot_icon'],
                    ['value' => $iconPath, 'type' => 'file']
                );
            }

            // Clear settings cache
            Cache::forget(config('constants.CACHE.SETTINGS'));

            DB::commit();

            // Re-fetch and return updated settings
            $updatedSettings = [];
            foreach ($settingKeys as $key) {
                $setting = Setting::where('name', $key)->first();
                $updatedSettings[$key] = $setting?->value;
            }

            return $this->jsonSuccess(__('Chatbot settings updated successfully'), $updatedSettings);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to update settings') . ': ' . $e->getMessage(), 500);
        }
    }

    // ==========================================
    // FAQ Buttons Management
    // ==========================================

    /**
     * List all FAQ buttons
     */
    public function indexFaqs(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 15), 100);
        $withTrashed = $request->boolean('with_trashed');
        $category = $request->input('category');

        $query = ChatbotFaq::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('question', 'like', "%{$search}%")
                  ->orWhere('answer', 'like', "%{$search}%");
            }))
            ->when($category, fn ($q) => $q->where('category', $category));

        $faqs = $query->orderBy('sort_order')->orderBy('id', 'desc')->paginate($perPage);

        return $this->jsonSuccess(__('Chatbot FAQs retrieved'), $faqs);
    }

    /**
     * List only soft-deleted FAQ buttons
     */
    public function trashedFaqs(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 15), 100);
        $category = $request->input('category');

        $query = ChatbotFaq::onlyTrashed()
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('question', 'like', "%{$search}%")
                  ->orWhere('answer', 'like', "%{$search}%");
            }))
            ->when($category, fn ($q) => $q->where('category', $category));

        $faqs = $query->orderBy('sort_order')->orderBy('id', 'desc')->paginate($perPage);

        return $this->jsonSuccess(__('Trashed Chatbot FAQs retrieved'), $faqs);
    }

    /**
     * Create a new FAQ button
     */
    public function storeFaq(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'question' => 'required|string|min:2|max:255',
            'answer' => 'required|string|min:2',
            'category' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();
            $data = $validator->validated();
            $data['is_active'] = $request->boolean('is_active', true);
            $data['sort_order'] = $request->input('sort_order', 0);
            $faq = ChatbotFaq::create($data);
            DB::commit();

            return $this->jsonSuccess(__('FAQ created successfully'), $faq, 201);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to create FAQ') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * Show a single FAQ
     */
    public function showFaq(int $id): JsonResponse
    {
        $this->ensureAdmin();

        $faq = ChatbotFaq::withTrashed()->find($id);
        if (!$faq) {
            return $this->jsonError(__('FAQ not found'), 404);
        }

        return $this->jsonSuccess(__('FAQ retrieved'), $faq);
    }

    /**
     * Update a FAQ button
     */
    public function updateFaq(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();

        $faq = ChatbotFaq::find($id);
        if (!$faq) {
            return $this->jsonError(__('FAQ not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'question' => 'sometimes|required|string|min:2|max:255',
            'answer' => 'sometimes|required|string|min:2',
            'category' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $faq->update($validator->validated());
        return $this->jsonSuccess(__('FAQ updated successfully'), $faq->fresh());
    }

    /**
     * Soft delete a FAQ
     */
    public function destroyFaq(int $id): JsonResponse
    {
        $this->ensureAdmin();

        $faq = ChatbotFaq::withTrashed()->find($id);
        if (!$faq) {
            return $this->jsonError(__('FAQ not found'), 404);
        }

        $faq->delete();
        return $this->jsonSuccess(__('FAQ deleted successfully'));
    }

    /**
     * Restore a soft-deleted FAQ
     */
    public function restoreFaq(int $id): JsonResponse
    {
        $this->ensureAdmin();

        $faq = ChatbotFaq::onlyTrashed()->find($id);
        if (!$faq) {
            return $this->jsonError(__('FAQ not found'), 404);
        }

        $faq->restore();
        return $this->jsonSuccess(__('FAQ restored successfully'), $faq->fresh());
    }

    // ==========================================
    // Knowledge Base Management
    // ==========================================

    /**
     * List all knowledge base entries
     */
    public function indexKnowledge(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 15), 100);
        $targetAudience = $request->input('target_audience');
        $courseId = $request->input('course_id');

        $query = ChatbotKnowledgeBase::query()
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            }))
            ->when($targetAudience, fn ($q) => $q->where('target_audience', $targetAudience))
            ->when($request->has('course_id'), function ($q) use ($courseId) {
                if ($courseId === 'null' || $courseId === null) {
                    $q->whereNull('course_id');
                } else if ($courseId === 'not_null') {
                    $q->whereNotNull('course_id');
                } else {
                    $q->where('course_id', $courseId);
                }
            });

        if ($request->boolean('count_only')) {
            return $this->jsonSuccess(__('Knowledge base count retrieved'), [
                'total' => $query->count()
            ]);
        }

        $entries = $query->orderBy('id', 'desc')->paginate($perPage);

        // Add file URL for entries with files
        $entries->getCollection()->transform(function ($entry) {
            if ($entry->file_path) {
                $entry->file_url = FileService::getFileUrl($entry->file_path);
            }
            return $entry;
        });

        return $this->jsonSuccess(__('Knowledge base entries retrieved'), $entries);
    }

    /**
     * Create a new knowledge base entry
     * Supports: direct text content OR file upload (.txt, .md, .csv, .json)
     */
    public function storeKnowledge(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|min:2|max:255',
            'content' => 'required_without:file|nullable|string',
            'file' => 'required_without:content|nullable|file|mimes:txt,csv,json|max:5120', // 5MB max
            'is_active' => 'nullable|boolean',
            'target_audience' => 'nullable|in:visitor,subscriber,course',
            'course_id' => 'nullable|integer|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $data = [
                'title' => $request->input('title'),
                'is_active' => $request->boolean('is_active', true),
                'target_audience' => $request->input('target_audience', 'visitor'),
                'course_id' => $request->input('course_id'),
            ];

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $data['content'] = file_get_contents($file->getRealPath());
                $data['file_path'] = FileService::upload($file, 'chatbot/knowledge');
                $data['file_type'] = $file->getClientOriginalExtension();
            } else {
                $data['content'] = $request->input('content');
            }

            $entry = ChatbotKnowledgeBase::create($data);
            DB::commit();

            return $this->jsonSuccess(__('Knowledge base entry created successfully'), $entry, 201);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to create knowledge base entry') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * Show a single knowledge base entry
     */
    public function showKnowledge(int $id): JsonResponse
    {
        $this->ensureAdmin();

        $entry = ChatbotKnowledgeBase::find($id);
        if (!$entry) {
            return $this->jsonError(__('Knowledge base entry not found'), 404);
        }

        if ($entry->file_path) {
            $entry->file_url = FileService::getFileUrl($entry->file_path);
        }

        return $this->jsonSuccess(__('Knowledge base entry retrieved'), $entry);
    }

    /**
     * Update a knowledge base entry
     */
    public function updateKnowledge(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();

        $entry = ChatbotKnowledgeBase::find($id);
        if (!$entry) {
            return $this->jsonError(__('Knowledge base entry not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|min:2|max:255',
            'content' => 'nullable|string',
            'file' => 'nullable|file|mimes:txt,md,csv,json,xml|max:5120',
            'is_active' => 'nullable|boolean',
            'target_audience' => 'nullable|in:visitor,subscriber,course',
            'course_id' => 'nullable|integer|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $data = [];

            if ($request->has('title')) {
                $data['title'] = $request->input('title');
            }

            if ($request->has('target_audience')) {
                $data['target_audience'] = $request->input('target_audience');
            }
            if ($request->has('course_id')) {
                $data['course_id'] = $request->input('course_id');
            }

            if ($request->has('is_active')) {
                $data['is_active'] = $request->boolean('is_active');
            }

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $data['content'] = file_get_contents($file->getRealPath());

                // Delete old file if exists
                if ($entry->file_path) {
                    FileService::delete($entry->file_path);
                }

                $data['file_path'] = FileService::upload($file, 'chatbot/knowledge');
                $data['file_type'] = $file->getClientOriginalExtension();
            } elseif ($request->has('content')) {
                $data['content'] = $request->input('content');
            }

            $entry->update($data);
            DB::commit();

            return $this->jsonSuccess(__('Knowledge base entry updated successfully'), $entry->fresh());
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to update knowledge base entry') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a knowledge base entry
     */
    public function destroyKnowledge(int $id): JsonResponse
    {
        $this->ensureAdmin();

        $entry = ChatbotKnowledgeBase::find($id);
        if (!$entry) {
            return $this->jsonError(__('Knowledge base entry not found'), 404);
        }

        // Delete associated file
        if ($entry->file_path) {
            FileService::delete($entry->file_path);
        }

        $entry->delete();
        return $this->jsonSuccess(__('Knowledge base entry deleted successfully'));
    }

    /**
     * Toggle knowledge base entry active status
     */
    public function toggleKnowledge(int $id): JsonResponse
    {
        $this->ensureAdmin();

        $entry = ChatbotKnowledgeBase::find($id);
        if (!$entry) {
            return $this->jsonError(__('Knowledge base entry not found'), 404);
        }

        $entry->update(['is_active' => !$entry->is_active]);

        $status = $entry->is_active ? 'activated' : 'deactivated';
        return $this->jsonSuccess(__('Knowledge base entry activated successfully'), $entry->fresh());
    }

    // ==========================================
    // Course Knowledge Management
    // ==========================================

    /**
     * Upload a knowledge file for a specific course
     */
    public function uploadCourseKnowledge(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'course_id' => 'required|integer|exists:courses,id',
            'file' => 'required|file|mimes:txt,csv,json|max:10240',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $file = $request->file('file');
            $courseId = (int) $request->input('course_id');

            // Read text content
            $content = file_get_contents($file->getRealPath());
            $filePath = FileService::upload($file, 'chatbot/course-knowledge');
            $fileType = $file->getClientOriginalExtension();

            // Upsert the knowledge base entry for this course
            $entry = ChatbotKnowledgeBase::updateOrCreate(
                ['course_id' => $courseId, 'target_audience' => 'course'],
                [
                    'title' => $file->getClientOriginalName(),
                    'content' => $content,
                    'file_path' => $filePath,
                    'file_type' => $fileType,
                    'is_active' => true,
                ]
            );

            // Also update the course's ai_knowledge_content field so processCourseMessage works
            \App\Models\Course\Course::where('id', $courseId)->update([
                'ai_knowledge_content' => $content,
                'chatbot_enabled' => true,
            ]);

            DB::commit();

            return $this->jsonSuccess(__('Course knowledge file uploaded successfully'), $entry, 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to upload course knowledge file') . ': ' . $e->getMessage(), 500);
        }
    }

    // ==========================================
    // Chat Conversations
    // ==========================================

    /**
     * List all chat conversations
     */
    public function indexConversations(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 20), 100);
        $type = $request->input('type');
        $courseId = $request->input('course_id');
        $userId = $request->input('user_id');

        $query = \App\Models\ChatbotConversation::with(['user:id,name,email', 'course:id,title'])
            ->when($search, function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('session_id', 'like', "%{$search}%");
            })
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->when($userId, fn ($q) => $q->where('user_id', $userId));

        $conversations = $query->orderBy('last_message_at', 'desc')->paginate($perPage);

        $conversations->getCollection()->transform(function ($conversation) {
            $conversation->user = $conversation->user ?? null;
            $conversation->user_name = $conversation->user ? $conversation->user->name : ($conversation->session_id ? 'Guest #' . substr($conversation->session_id, 0, 8) : 'Guest');
            $conversation->user_email = $conversation->user ? $conversation->user->email : 'N/A';
            return $conversation;
        });

        return $this->jsonSuccess(__('Chat conversations retrieved'), $conversations);
    }

    /**
     * Show a single chat conversation and its messages
     */
    public function showConversation(int $id): JsonResponse
    {
        $this->ensureAdmin();

        $conversation = \App\Models\ChatbotConversation::with([
            'user:id,name,email',
            'course:id,title',
            'messages' => function ($q) {
                $q->orderBy('created_at', 'asc');
            }
        ])->find($id);

        if (!$conversation) {
            return $this->jsonError(__('Conversation not found'), 404);
        }

        $conversation->user_name = $conversation->user ? $conversation->user->name : ($conversation->session_id ? 'Guest #' . substr($conversation->session_id, 0, 8) : 'Guest');
        $conversation->user_email = $conversation->user ? $conversation->user->email : 'N/A';

        return $this->jsonSuccess(__('Chat conversation retrieved'), $conversation);
    }

    // ==========================================
    // Chat History (Logs)
    // ==========================================

    /**
     * List all chat history/logs
     */
    public function indexLogs(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 20), 100);
        $type = $request->input('type');
        $courseId = $request->input('course_id');
        $userId = $request->input('user_id');

        $query = ChatbotMessage::with(['user:id,name,email', 'course:id,title'])
            ->when($search, function ($q) use ($search) {
                $q->where('message', 'like', "%{$search}%")
                  ->orWhere('reply', 'like', "%{$search}%");
            })
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->when($userId, fn ($q) => $q->where('user_id', $userId));

        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $logs->getCollection()->transform(function ($log) {
            $log->user = $log->user ?? null;
            $log->user_name = $log->user ? $log->user->name : 'Guest';
            $log->user_email = $log->user ? $log->user->email : 'N/A';
            return $log;
        });

        return $this->jsonSuccess(__('Chat history retrieved'), $logs);
    }

    // ==========================================
    // Test Chat
    // ==========================================

    /**
     * Test the chatbot from admin panel
     */
    public function testChat(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:1|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $service = new ChatBotService();
        $result = $service->processMessage($request->input('message'));

        return $this->jsonSuccess(__('Chat test response'), $result);
    }

    // ==========================================
    // Stats
    // ==========================================

    /**
     * Get chatbot dashboard statistics
     */
    public function getStats(): JsonResponse
    {
        $this->ensureAdmin();

        return $this->jsonSuccess(__('Chatbot stats retrieved'), [
            'total_faqs' => ChatbotFaq::count(),
            'total_knowledge_base' => ChatbotKnowledgeBase::count(),
            'total_conversations' => \App\Models\ChatbotConversation::count(),
            'total_messages' => ChatbotMessage::count(),
        ]);
    }
}

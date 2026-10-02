<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Course\Course;
use App\Models\ChatbotFaq;
use App\Models\ChatbotKnowledgeBase;
use App\Models\ChatbotMessage;
use App\Models\Setting;
use App\Services\ChatBotService;
use App\Services\DocumentParserService;
use App\Services\FileService;
use App\Services\WebPageKnowledgeService;
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
        $isKeyConfigured = false;
        foreach ($settingKeys as $key) {
            $setting = Setting::where('name', $key)->first();
            $val = $setting?->value;
            if ($key === 'openai_api_key') {
                $isKeyConfigured = !empty($val);
                $val = $isKeyConfigured ? '••••••••' : '';
            }
            $settings[$key] = $val;
        }

        $settings['openai_api_key_configured'] = $isKeyConfigured;

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
                    if ($key === 'openai_api_key') {
                        $rawVal = trim((string) $request->input($key));
                        // Ignore empty or placeholder strings so real key is never overwritten accidentally
                        if ($rawVal === '' || $rawVal === '••••••••' || str_starts_with($rawVal, '••••') || str_ends_with($rawVal, '...')) {
                            continue;
                        }
                        $value = $rawVal;
                    } else {
                        $value = $key === 'chatbot_enabled'
                            ? ($request->boolean($key) ? '1' : '0')
                            : (string) $request->input($key);
                    }

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

            // Re-fetch and return updated settings securely
            $updatedSettings = [];
            $isKeyConfigured = false;
            foreach ($settingKeys as $key) {
                $setting = Setting::where('name', $key)->first();
                $val = $setting?->value;
                if ($key === 'openai_api_key') {
                    $isKeyConfigured = !empty($val);
                    $val = $isKeyConfigured ? '••••••••' : '';
                }
                $updatedSettings[$key] = $val;
            }
            $updatedSettings['openai_api_key_configured'] = $isKeyConfigured;

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
     * Supports: direct text content OR file upload (.txt, .md, .csv, .json, .pdf, .docx)
     */
    public function storeKnowledge(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|min:2|max:255',
            'content' => 'required_without_all:file,source_url|nullable|string',
            'source_url' => 'nullable|url|max:500',
            'file' => 'required_without_all:content,source_url|nullable|file|mimes:txt,csv,json,pdf,docx,md|max:10240', // 10MB max
            'is_active' => 'nullable|boolean',
            'target_audience' => 'nullable|in:visitor,subscriber,course',
            'course_id' => 'nullable|integer|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $page = $this->pageFromRequest($request);
        if ($page instanceof JsonResponse) {
            return $page;
        }

        try {
            DB::beginTransaction();

            $targetAudience = $request->input('target_audience', 'visitor');
            $courseId = $request->input('course_id') ? (int) $request->input('course_id') : null;

            $data = [
                'title' => $request->input('title'),
                'is_active' => $request->boolean('is_active', true),
                'target_audience' => $targetAudience,
                'course_id' => $courseId,
                'processing_status' => 'queued',
            ];

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $data['file_type'] = strtolower((string) $file->getClientOriginalExtension());
                $data['file_text'] = $this->extractUploadedKnowledgeText($file);
                $data['file_path'] = FileService::upload($file, 'chatbot/knowledge');
            }

            $pageText = is_array($page) ? $page['text'] : '';
            $fileText = (string) ($data['file_text'] ?? '');
            unset($data['file_text']);
            $joined = app(WebPageKnowledgeService::class)->joinTexts(
                trim((string) $request->input('content', '')),
                $fileText,
                $pageText,
            );
            $data['content'] = $joined !== '' ? $joined : null;
            if (! array_key_exists('file_path', $data)) {
                $data['file_path'] = null;
                $data['file_type'] = is_array($page) ? 'url' : null;
            }
            if (is_array($page)) {
                $data['source_url'] = $page['url'];
            }

            $entry = ChatbotKnowledgeBase::create($data);
            DB::commit();

            // Dispatch vector ingestion job for semantic chunking & indexing
            \App\Jobs\ProcessKnowledgeIngestionJob::dispatch(
                $entry->id,
                $courseId,
                $targetAudience === 'visitor' ? 'visitor' : ($targetAudience === 'course' ? 'course' : 'subscriber')
            );

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
            'source_url' => 'nullable|url|max:500',
            'file' => 'nullable|file|mimes:txt,md,csv,json,xml,pdf,docx|max:10240',
            'is_active' => 'nullable|boolean',
            'target_audience' => 'nullable|in:visitor,subscriber,course',
            'course_id' => 'nullable|integer|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $page = $this->pageFromRequest($request);
        if ($page instanceof JsonResponse) {
            return $page;
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
                if ($entry->file_path && $entry->file_type !== 'url') {
                    FileService::delete($entry->file_path);
                }
                $data['file_type'] = strtolower((string) $file->getClientOriginalExtension());
                $data['file_text'] = $this->extractUploadedKnowledgeText($file);
                $data['file_path'] = FileService::upload($file, 'chatbot/knowledge');
            }

            if (is_array($page)) {
                $data['source_url'] = $page['url'];
                if (! $request->hasFile('file') && $entry->file_type === 'url') {
                    $data['file_path'] = null;
                    $data['file_type'] = 'url';
                }
            }

            if ($request->hasFile('file') || is_array($page) || $request->has('content')) {
                $fileText = (string) ($data['file_text'] ?? '');
                $existing = ($request->hasFile('file') && is_array($page)) || $request->has('content')
                    ? ''
                    : trim((string) $entry->content);
                $data['content'] = app(WebPageKnowledgeService::class)->joinTexts(
                    $existing,
                    trim((string) $request->input('content', '')),
                    $fileText,
                    is_array($page) ? $page['text'] : '',
                );
            }
            unset($data['file_text']);

            $data['processing_status'] = 'queued';
            $data['failure_reason'] = null;

            $entry->update($data);
            DB::commit();

            // Refresh vector chunks via ingestion job
            \App\Jobs\ProcessKnowledgeIngestionJob::dispatch(
                $entry->id,
                $entry->course_id,
                $entry->target_audience === 'visitor' ? 'visitor' : ($entry->target_audience === 'course' ? 'course' : 'subscriber')
            );

            return $this->jsonSuccess(__('Knowledge base entry updated successfully'), $entry->fresh());
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to update knowledge base entry') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a knowledge base entry and cascade delete vector chunks
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

        // Delete associated vector chunks so RAG never returns stale data
        \App\Models\ChatbotVectorChunk::where('knowledge_base_id', $id)->delete();

        $entry->delete();
        return $this->jsonSuccess(__('Knowledge base entry deleted successfully'));
    }

    /**
     * Toggle knowledge base entry active status and sync to vector chunks
     */
    public function toggleKnowledge(int $id): JsonResponse
    {
        $this->ensureAdmin();

        $entry = ChatbotKnowledgeBase::find($id);
        if (!$entry) {
            return $this->jsonError(__('Knowledge base entry not found'), 404);
        }

        $newActive = !$entry->is_active;
        $entry->update(['is_active' => $newActive]);

        // Synchronize active status to vector chunks
        \App\Models\ChatbotVectorChunk::where('knowledge_base_id', $id)->update(['is_active' => $newActive]);

        return $this->jsonSuccess(__('Knowledge base entry updated successfully'), $entry->fresh());
    }

    /**
     * Reindex a knowledge base entry
     */
    public function reindexKnowledge(int $id): JsonResponse
    {
        $this->ensureAdmin();

        $entry = ChatbotKnowledgeBase::find($id);
        if (!$entry) {
            return $this->jsonError(__('Knowledge base entry not found'), 404);
        }

        $entry->update([
            'processing_status' => 'queued',
            'failure_reason' => null,
        ]);

        \App\Jobs\ProcessKnowledgeIngestionJob::dispatch(
            $entry->id,
            $entry->course_id,
            $entry->target_audience === 'visitor' ? 'visitor' : ($entry->target_audience === 'course' ? 'course' : 'subscriber')
        );

        return $this->jsonSuccess(__('Knowledge base reindexing queued successfully'), $entry->fresh());
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
            'source_url' => 'required_without:file|nullable|url|max:500',
            'file' => 'required_without:source_url|nullable|file|mimes:txt,csv,json,pdf,docx,md|max:10240',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $page = $this->pageFromRequest($request);
        if ($page instanceof JsonResponse) {
            return $page;
        }

        try {
            DB::beginTransaction();

            $courseId = (int) $request->input('course_id');
            $course = Course::query()->find($courseId);

            $fileText = '';
            $filePath = null;
            $fileType = null;
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $fileType = strtolower((string) $file->getClientOriginalExtension());
                $fileText = $this->extractUploadedKnowledgeText($file);
                $filePath = FileService::upload($file, FileService::coursePath($course?->slug, 'knowledge'));
            }

            $existingContent = (! $request->hasFile('file') || ! is_array($page))
                ? trim((string) ($course?->ai_knowledge_content ?? ''))
                : '';
            if ($request->hasFile('file') && is_array($page)) {
                $existingContent = '';
            }
            $content = app(WebPageKnowledgeService::class)->joinTexts(
                $existingContent,
                $fileText,
                is_array($page) ? $page['text'] : '',
            );

            $attributes = [
                'title' => is_array($page) && $page['title'] !== ''
                    ? $page['title']
                    : ($request->hasFile('file') ? $request->file('file')->getClientOriginalName() : ($course?->title ?: 'معرفة الكورس')),
                'content' => $content !== '' ? $content : null,
                'is_active' => true,
                'processing_status' => 'queued',
            ];
            if ($filePath !== null) {
                $attributes['file_path'] = $filePath;
                $attributes['file_type'] = $fileType;
            } elseif (is_array($page)) {
                $attributes['file_type'] = 'url';
            }
            if (is_array($page)) {
                $attributes['source_url'] = $page['url'];
            }

            if ($course) {
                $courseUpdates = [
                    'ai_processing_status' => 'queued',
                ];
                if ($request->has('chatbot_enabled')) {
                    $courseUpdates['chatbot_enabled'] = $request->boolean('chatbot_enabled');
                }
                if ($content !== '') {
                    $courseUpdates['ai_knowledge_content'] = $content;
                }
                if ($filePath !== null) {
                    $courseUpdates['ai_knowledge_file'] = $filePath;
                }
                if (is_array($page)) {
                    $courseUpdates['ai_knowledge_url'] = $page['url'];
                }
                $course->update($courseUpdates);
            }

            $entry = ChatbotKnowledgeBase::updateOrCreate(
                ['course_id' => $courseId, 'target_audience' => 'course'],
                $attributes,
            );

            DB::commit();

            \App\Jobs\ProcessKnowledgeIngestionJob::dispatch($entry->id, $courseId, 'course');

            return $this->jsonSuccess(__('Course knowledge saved successfully'), $entry, 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to save course knowledge') . ': ' . $e->getMessage(), 500);
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

        $query = \App\Models\ChatbotConversation::with(['user:id,name,email,mobile,type', 'course:id,title', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->when($search, function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('session_id', 'like', "%{$search}%");
            })
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->when($userId, fn ($q) => $q->where('user_id', $userId));

        $conversations = $query->orderBy('last_message_at', 'desc')->paginate($perPage);

        $conversations->getCollection()->transform(function ($conversation) {
            $user = $conversation->user;
            $conversation->user_name = $user ? $user->name : ($conversation->session_id ? 'Guest #' . substr($conversation->session_id, 0, 8) : 'Guest');
            $conversation->user_email = $user ? $user->email : 'N/A';
            $conversation->user_phone = $user?->mobile ?: 'N/A';
            $conversation->user_role = $user?->type ?: ($user ? 'student' : 'guest');
            $conversation->is_guest = empty($conversation->user_id);
            $lastMsg = $conversation->messages->first();
            $conversation->last_message = $lastMsg ? $lastMsg->message : ($conversation->title ?: 'لا توجد رسالة');
            return $conversation;
        });

        return $this->jsonSuccess(__('Chat conversations retrieved'), $conversations);
    }

    /**
     * Show a single chat conversation and its complete chronological messages transcript
     */
    public function showConversation(int $id): JsonResponse
    {
        $this->ensureAdmin();

        $conversation = \App\Models\ChatbotConversation::with([
            'user:id,name,email,mobile,type',
            'course:id,title',
            'messages' => function ($q) {
                $q->orderBy('created_at', 'asc');
            }
        ])->find($id);

        if (!$conversation) {
            return $this->jsonError(__('Conversation not found'), 404);
        }

        $formattedMessages = [];
        foreach ($conversation->messages as $msg) {
            if (!empty($msg->message)) {
                $formattedMessages[] = [
                    'id' => $msg->id . '_user',
                    'conversation_id' => $conversation->id,
                    'sender' => 'user',
                    'role' => 'user',
                    'message' => $msg->message,
                    'type' => $msg->type,
                    'created_at' => $msg->created_at?->toISOString() ?? (string) $msg->created_at,
                ];
            }
            if (!empty($msg->reply)) {
                $formattedMessages[] = [
                    'id' => $msg->id . '_bot',
                    'conversation_id' => $conversation->id,
                    'sender' => 'bot',
                    'role' => 'bot',
                    'message' => $msg->reply,
                    'type' => $msg->type,
                    'created_at' => $msg->created_at?->toISOString() ?? (string) $msg->created_at,
                ];
            }
        }

        $conversationData = $conversation->toArray();
        $conversationData['messages'] = $formattedMessages;
        $user = $conversation->user;
        $conversationData['user_name'] = $user ? $user->name : ($conversation->session_id ? 'Guest #' . substr($conversation->session_id, 0, 8) : 'Guest');
        $conversationData['user_email'] = $user ? $user->email : 'N/A';
        $conversationData['user_phone'] = $user?->mobile ?: 'N/A';
        $conversationData['user_role'] = $user?->type ?: ($user ? 'student' : 'guest');
        $conversationData['is_guest'] = empty($conversation->user_id);

        return $this->jsonSuccess(__('Chat conversation retrieved'), $conversationData);
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

    private function extractUploadedKnowledgeText(\Illuminate\Http\UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            return '';
        }

        return trim(app(DocumentParserService::class)->extractText(
            $path,
            strtolower((string) $file->getClientOriginalExtension()),
        ));
    }

    /**
     * @return array{url: string, title: string, text: string}|null|JsonResponse
     */
    private function pageFromRequest(Request $request): array|null|JsonResponse
    {
        if (! $request->filled('source_url')) {
            return null;
        }

        try {
            return app(WebPageKnowledgeService::class)->extract((string) $request->input('source_url'));
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }
}

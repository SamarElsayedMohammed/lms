<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Lecture\LectureResource;
use App\Models\Course\CourseLanguage;
use App\Services\FileService;
use App\Services\HelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CourseAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Create a new course with optional curriculum (chapters + standalone lessons).
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-create');

        // ── Validation ──────────────────────────────────────────────
        $maxVideoKb = (int) ini_get('upload_max_filesize') * 1024;

        $validator = Validator::make($request->all(), [
            'title'                         => 'required|string|min:2|max:255',
            'category_id'                   => 'required|exists:categories,id',
            'short_description'             => 'nullable|string',
            'description'                   => 'nullable|string',
            'level'                         => 'nullable|in:beginner,intermediate,advanced',
            'is_free'                       => 'nullable|boolean',
            'price'                         => 'nullable|numeric|min:0',
            'discount_price'                => 'nullable|numeric|min:0',
            'status'                        => 'nullable|in:draft,pending,publish',
            'instructor_id'                 => 'nullable|exists:users,id',
            'sequential_learning'           => 'nullable|boolean',
            'certificate_enabled'           => 'nullable|boolean',
            'certificate_fee'               => 'nullable|numeric|min:0',
            'thumbnail_url'                 => 'nullable|url',
            'thumbnail'                     => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            'promo_video_url'               => 'nullable|url',
            'intro_video'                   => "nullable|file|max:{$maxVideoKb}",
            'meta_title'                    => 'nullable|string|max:255',
            'meta_description'              => 'nullable|string',
            'meta_keywords'                 => 'nullable|string',
            'tags'                          => 'nullable|array',
            'tags.*'                        => 'nullable|string|max:255',
            'curriculum_sections'           => 'nullable|array',
            'curriculum_sections.*.title'   => 'required_with:curriculum_sections|string',
            'curriculum_sections.*.lessons' => 'nullable|array',
            'curriculum_sections.*.lessons.*.type' => 'nullable|in:video,file,youtube_url',
            'curriculum_sections.*.lessons.*.file' => 'nullable|file|max:51200',
            'curriculum_sections.*.lessons.*.materials'       => 'nullable|array',
            'curriculum_sections.*.lessons.*.materials.*.file' => 'nullable|file|max:51200',
            'standalone_lessons'            => 'nullable|array',
            'standalone_lessons.*.type'     => 'nullable|in:video,file,youtube_url',
            'standalone_lessons.*.file'     => 'nullable|file|max:51200',
            'standalone_lessons.*.materials'       => 'nullable|array',
            'standalone_lessons.*.materials.*.file' => 'nullable|file|max:51200',
            'is_featured'                   => 'nullable|boolean',
            'ai_knowledge_file'             => 'nullable|file|mimes:txt,md,csv,json,xml|max:5120',
            'chatbot_enabled'               => 'nullable|boolean',
            'chatbot_name'                  => 'nullable|string|max:100',
            'chatbot_welcome_message'       => 'nullable|string|max:500',
            'chatbot_system_prompt'         => 'nullable|string',
            'chatbot_max_tokens'            => 'nullable|integer|min:10|max:4000',
            'initial_views'                 => 'nullable|integer|min:0',
            'initial_students'              => 'nullable|integer|min:0',
            'initial_rating'                => 'nullable|numeric|min:0|max:5',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        // ── Build course data ───────────────────────────────────────
        $instructorId  = (int) ($request->input('instructor_id') ?? Auth::id());
        $isFree        = $request->has('is_free') ? $request->boolean('is_free') : true;
        $courseType     = $isFree ? 'free' : 'paid';
        $price         = $courseType === 'paid' ? round((float) $request->input('price', 0), 2) : null;
        $discountPrice = $courseType === 'paid' && $request->filled('discount_price')
            ? round((float) $request->input('discount_price'), 2)
            : null;
        $status = $request->input('status', 'draft');
        if (!in_array($status, ['draft', 'pending', 'publish'], true)) {
            $status = 'draft';
        }
        $approvalStatus = $status === 'publish' ? 'approved' : null;
        $isActive       = ($status === 'publish' && $approvalStatus === 'approved') ? 1 : 0;

        // Content structure determination
        $hasSections   = is_array($request->input('curriculum_sections')) && count($request->input('curriculum_sections')) > 0;
        $hasStandalone = is_array($request->input('standalone_lessons')) && count($request->input('standalone_lessons')) > 0;
        if ($hasSections) {
            $contentStructure = 'chapters';
        } elseif ($hasStandalone) {
            $contentStructure = 'lessons';
        } else {
            $contentStructure = 'chapters';
        }

        $title = trim((string) $request->input('title'));
        $slug  = HelperService::generateUniqueSlug(Course::class, $title);

        // Thumbnail
        if ($request->hasFile('thumbnail')) {
            $thumbnail = FileService::compressAndUpload($request->file('thumbnail'), 'courses/thumbnail');
        } elseif ($request->filled('thumbnail_url')) {
            $thumbnail = $request->input('thumbnail_url');
        } else {
            $thumbnail = null;
        }

        // Intro video
        if ($request->hasFile('intro_video')) {
            $introVideoType = 'file';
            $introVideo     = FileService::compressAndUpload($request->file('intro_video'), 'courses/intro_video');
        } elseif ($request->filled('promo_video_url')) {
            $introVideoType = 'url';
            $introVideo     = $request->input('promo_video_url');
        } else {
            $introVideoType = null;
            $introVideo     = null;
        }

        $metaDescription = $request->input('meta_description') ?? $request->input('description');
        $languageId      = CourseLanguage::where('is_active', 1)->value('id');
        $certificateFee  = $request->boolean('certificate_enabled')
            ? round((float) ($request->input('certificate_fee', 0)), 2)
            : null;

        // ── Persist ─────────────────────────────────────────────────
        try {
            DB::beginTransaction();

            $course = Course::create([
                'title'              => $title,
                'slug'               => $slug,
                'short_description'  => $request->input('short_description'),
                'meta_description'   => $metaDescription,
                'meta_title'         => $request->input('meta_title'),
                'meta_keywords'      => $request->input('meta_keywords'),
                'category_id'        => (int) $request->input('category_id'),
                'user_id'            => $instructorId,
                'level'              => $request->input('level', 'beginner'),
                'course_type'        => $courseType,
                'price'              => $price,
                'discount_price'     => $discountPrice,
                'status'             => $status,
                'approval_status'    => $approvalStatus,
                'is_active'          => $isActive,
                'is_free'            => $isFree,
                'sequential_access'  => $request->boolean('sequential_learning'),
                'certificate_enabled' => $request->boolean('certificate_enabled'),
                'certificate_fee'    => $certificateFee,
                'thumbnail'          => $thumbnail,
                'intro_video'        => $introVideo,
                'intro_video_type'   => $introVideoType,
                'content_structure'  => $contentStructure,
                'language_id'        => $languageId,
                'is_featured'        => $request->boolean('is_featured'),
                'chatbot_enabled'    => $request->boolean('chatbot_enabled'),
                'chatbot_name'       => $request->input('chatbot_name'),
                'chatbot_welcome_message' => $request->input('chatbot_welcome_message'),
                'chatbot_system_prompt'   => $request->input('chatbot_system_prompt'),
                'chatbot_max_tokens'      => $request->filled('chatbot_max_tokens') ? (int) $request->input('chatbot_max_tokens') : null,
                'initial_views'           => $request->filled('initial_views') ? (int) $request->input('initial_views') : 0,
                'initial_students'        => $request->filled('initial_students') ? (int) $request->input('initial_students') : 0,
                'initial_rating'          => $request->filled('initial_rating') ? (float) $request->input('initial_rating') : 0,
            ]);

            // AI Knowledge Base file for course chatbot
            if ($request->hasFile('ai_knowledge_file')) {
                $knowledgeFile = $request->file('ai_knowledge_file');
                $filePath = FileService::upload($knowledgeFile, 'courses/ai_knowledge');
                $fileContent = file_get_contents($knowledgeFile->getRealPath());
                $course->update([
                    'ai_knowledge_file' => $filePath,
                    'ai_knowledge_content' => $fileContent,
                ]);
            }

            // ── Tags ────────────────────────────────────────────────
            $tags = $request->input('tags', []);
            if (is_array($tags) && $tags !== []) {
                $normalized = array_values(array_filter(array_map(
                    static fn ($t) => is_string($t) ? trim($t) : '',
                    $tags,
                )));
                if ($normalized !== []) {
                    $tagIds = HelperService::getOrStoreTagId($normalized);
                    $course->tags()->sync($tagIds);
                }
            }

            // ── Instructor sync ─────────────────────────────────────
            $instructors = HelperService::getInstructorsWithCourseRelatedPermissions([$instructorId]);
            if ($instructors->isNotEmpty()) {
                $course->instructors()->sync($instructors->pluck('id'));
            } else {
                $course->instructors()->sync([$instructorId]);
            }

            // ── Curriculum: sections (chapters) ─────────────────────
            $sections = $request->input('curriculum_sections', []);
            if (is_array($sections)) {
                foreach ($sections as $sectionOrder => $section) {
                    $chapter = CourseChapter::create([
                        'course_id'     => $course->id,
                        'user_id'       => $instructorId,
                        'title'         => $section['title'] ?? 'Untitled Section',
                        'type'          => 'default',
                        'is_active'     => true,
                        'chapter_order' => $sectionOrder + 1,
                    ]);

                    $lessons = $section['lessons'] ?? [];
                    if (is_array($lessons)) {
                        foreach ($lessons as $lessonOrder => $lesson) {
                                $contentFile = $request->file("curriculum_sections.{$sectionOrder}.lessons.{$lessonOrder}.file");
                                $lecture = $this->buildLectureData($lesson, $chapter->id, $instructorId, $lessonOrder + 1, $contentFile);
                                $materialFiles = $request->file("curriculum_sections.{$sectionOrder}.lessons.{$lessonOrder}.materials") ?? [];
                                $this->buildLectureResources($lesson['materials'] ?? [], $materialFiles, $lecture->id, $instructorId);
                            }
                        }
                    }
                }

                // Build new curriculum - Standalone lessons
                $standaloneLessons = $request->input('standalone_lessons', []);
                if (is_array($standaloneLessons) && count($standaloneLessons) > 0) {
                    $defaultChapter = CourseChapter::create([
                        'course_id'     => $course->id,
                        'user_id'       => $instructorId,
                        'title'         => 'default',
                        'type'          => 'standalone',
                        'is_active'     => true,
                        'chapter_order' => count($sections) + 1,
                    ]);

                    foreach ($standaloneLessons as $lessonOrder => $lesson) {
                        $contentFile = $request->file("standalone_lessons.{$lessonOrder}.file");
                        $lecture = $this->buildLectureData($lesson, $defaultChapter->id, $instructorId, $lessonOrder + 1, $contentFile);
                        $materialFiles = $request->file("standalone_lessons.{$lessonOrder}.materials") ?? [];
                        $this->buildLectureResources($lesson['materials'] ?? [], $materialFiles, $lecture->id, $instructorId);
                    }
                }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->jsonError(__('Failed to create course: ') . $e->getMessage(), 500);
        }

        $course->refresh()->load([
            'user', 'category', 'tags', 'instructors', 'language', 'learnings', 'requirements',
            'chapters' => fn ($q) => $q->orderBy('chapter_order'),
            'chapters.lectures' => fn ($q) => $q->orderBy('chapter_order'),
            'chapters.lectures.resources' => fn ($q) => $q->orderBy('order'),
        ]);

        return $this->jsonSuccess(__('Course created successfully'), $this->buildCourseResponse($course), 201);
    }

    // ── Private helpers for curriculum building ─────────────────────

    /**
     * Parse a duration string into [hours, minutes, seconds].
     */
    private function parseDuration(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === '0' || $raw === '00') {
            return [0, 0, 0];
        }

        $raw = (string) $raw;
        if (is_numeric($raw)) {
            $n = (int) $raw;
            return [(int) ($n / 3600), (int) (($n % 3600) / 60), $n % 60];
        }

        $parts = explode(':', $raw);
        if (count($parts) === 3) {
            return [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
        }
        if (count($parts) === 2) {
            return [0, (int) $parts[0], (int) $parts[1]];
        }

        return [0, 0, 0];
    }

    /**
     * Create a single lecture from lesson data.
     */
    private function buildLectureData(
        array $lesson,
        int $chapterId,
        int $userId,
        int $order,
        ?\Illuminate\Http\UploadedFile $contentFile = null,
    ): CourseChapterLecture {
        [$hours, $minutes, $seconds] = $this->parseDuration($lesson['duration'] ?? null);
        $rawType = $lesson['type'] ?? 'video';

        if ($contentFile) {
            $filePath = FileService::upload($contentFile, 'course-chapters/lectures');
            $extension = strtolower($contentFile->getClientOriginalExtension());
            $videoExtensions = ['mp4', 'avi', 'mov', 'webm', 'mkv', 'flv', 'wmv'];

            // Auto-detect video duration
            if (in_array($extension, $videoExtensions, true)) {
                try {
                    $getID3 = new \getID3();
                    $fileInfo = $getID3->analyze($contentFile->getRealPath());
                    $totalSeconds = (int) round($fileInfo['playtime_seconds'] ?? 0);

                    if ($totalSeconds > 0) {
                        $hours   = (int) floor($totalSeconds / 3600);
                        $minutes = (int) floor(($totalSeconds % 3600) / 60);
                        $seconds = (int) ($totalSeconds % 60);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Could not auto-detect video duration in admin API', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return CourseChapterLecture::create([
                'user_id'           => $userId,
                'course_chapter_id' => $chapterId,
                'title'             => $lesson['title'] ?? 'Untitled Lesson',
                'slug'              => HelperService::generateUniqueSlug(CourseChapterLecture::class, $lesson['title'] ?? 'untitled'),
                'type'              => 'file',
                'file'              => $filePath,
                'file_extension'    => $contentFile->getClientOriginalExtension(),
                'youtube_url'       => null,
                'hours'             => $hours,
                'minutes'           => $minutes,
                'seconds'           => $seconds,
                'chapter_order'     => $order,
                'is_active'         => true,
                'free_preview'      => false,
            ]);
        }

        $type = $rawType === 'file' ? 'file' : (($rawType === 'video') ? 'youtube_url' : $rawType);

        return CourseChapterLecture::create([
            'user_id'           => $userId,
            'course_chapter_id' => $chapterId,
            'title'             => $lesson['title'] ?? 'Untitled Lesson',
            'slug'              => HelperService::generateUniqueSlug(CourseChapterLecture::class, $lesson['title'] ?? 'untitled'),
            'type'              => $type,
            'youtube_url'       => $lesson['content_url'] ?? null,
            'hours'             => $hours,
            'minutes'           => $minutes,
            'seconds'           => $seconds,
            'chapter_order'     => $order,
            'is_active'         => true,
            'free_preview'      => false,
        ]);
    }

    /**
     * Create material resources for a lecture.
     * Supports both file uploads and URL links.
     */
    private function buildLectureResources(array $materials, array $materialFiles, int $lectureId, int $userId): void
    {
        if (!is_array($materials)) {
            return;
        }

        foreach ($materials as $index => $material) {
            // Check if a file was uploaded for this material
            $uploadedFile = $materialFiles[$index]['file'] ?? null;

            if ($uploadedFile) {
                // File upload mode
                $filePath = FileService::upload($uploadedFile, 'lecture_resources');
                $fileExtension = $uploadedFile->getClientOriginalExtension();

                LectureResource::create([
                    'user_id'        => $userId,
                    'lecture_id'     => $lectureId,
                    'title'          => $material['title'] ?? $uploadedFile->getClientOriginalName(),
                    'type'           => 'file',
                    'file'           => $filePath,
                    'file_extension' => $fileExtension,
                    'is_active'      => true,
                    'order'          => $index,
                ]);
            } elseif (!empty($material['url']) || !empty($material['title'])) {
                // URL mode (backward compatible)
                LectureResource::create([
                    'user_id'    => $userId,
                    'lecture_id' => $lectureId,
                    'title'      => $material['title'] ?? null,
                    'type'       => 'url',
                    'url'        => $material['url'] ?? '',
                    'is_active'  => true,
                    'order'      => $index,
                ]);
            }
        }
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-list');

        $query = Course::without('taxes')
            ->with(['user', 'category'])
            ->when($request->search, fn ($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->approval_status, fn ($q) => $q->where('approval_status', $request->approval_status))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->with_trashed, fn ($q) => $q->withTrashed());

        $perPage = min((int) $request->input('per_page', 15), 100);
        $courses = $query->orderBy('id', 'desc')->paginate($perPage);

        return $this->jsonSuccess(__('Courses retrieved'), $courses);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-list');

        $course = Course::without('taxes')
            ->with([
                'user.instructor_details.personal_details',
                'category',
                'chapters' => fn ($q) => $q->orderBy('chapter_order'),
                'chapters.lectures' => fn ($q) => $q->orderBy('chapter_order'),
                'chapters.lectures.resources' => fn ($q) => $q->orderBy('order'),
                'tags',
                'instructors.instructor_details.personal_details',
                'language',
                'learnings',
                'requirements',
            ])
            ->withTrashed()
            ->find($id);

        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        return $this->jsonSuccess(__('Course retrieved'), $this->buildCourseResponse($course));
    }

    public function students(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-list');

        $course = Course::withTrashed()->find($id);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        $query = $course->getActiveStudentsQuery()
            ->select(['id', 'name', 'email', 'phone', 'created_at', 'type', 'is_active', 'profile_image'])
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($sq) use ($request) {
                    $sq->where('name', 'like', "%{$request->search}%")
                       ->orWhere('email', 'like', "%{$request->search}%")
                       ->orWhere('phone', 'like', "%{$request->search}%");
                });
            });

        $perPage = min((int) $request->input('per_page', 15), 100);
        $students = $query->orderBy('id', 'desc')->paginate($perPage);

        return $this->jsonSuccess(__('Course students retrieved'), $students);
    }

    /**
     * Build a structured course response that separates curriculum_sections
     * (real chapters) from standalone_lessons (the hidden 'standalone' chapter).
     */
    private function buildCourseResponse(Course $course): array
    {
        $courseData = $course->toArray();

        // ── Split chapters: real sections vs standalone container ────
        $curriculumSections = [];
        $standaloneLessons  = [];

        foreach ($course->chapters as $chapter) {
            if ($chapter->type === 'standalone') {
                // هذا الشابتر يحتوي على دروس مستقلة فقط
                foreach ($chapter->lectures as $lecture) {
                    $standaloneLessons[] = $this->formatLecture($lecture);
                }
            } else {
                // شابتر حقيقي
                $lessons = [];
                foreach ($chapter->lectures as $lecture) {
                    $lessons[] = $this->formatLecture($lecture);
                }
                $curriculumSections[] = [
                    'id'            => $chapter->id,
                    'title'         => $chapter->title,
                    'chapter_order' => $chapter->chapter_order,
                    'is_active'     => $chapter->is_active,
                    'type'          => $chapter->type,
                    'lessons'       => $lessons,
                ];
            }
        }

        // إزالة chapters الخام واستبدالها بالبنية المنظمة
        unset($courseData['chapters']);

        $courseData['curriculum_sections']  = $curriculumSections;
        $courseData['standalone_lessons']   = $standaloneLessons;
        $courseData['has_sections']         = count($curriculumSections) > 0;
        $courseData['has_standalone']       = count($standaloneLessons) > 0;
        $courseData['total_sections']       = count($curriculumSections);
        $courseData['total_lessons']        = array_sum(array_map(
            fn ($s) => count($s['lessons']), $curriculumSections
        )) + count($standaloneLessons);

        $courseData['has_ai_assistant'] = !empty($course->getRawOriginal('ai_knowledge_content'));
        unset($courseData['ai_knowledge_content']);

        return $courseData;
    }

    /**
     * Format a single lecture with its materials.
     */
    private function formatLecture(\App\Models\Course\CourseChapter\Lecture\CourseChapterLecture $lecture): array
    {
        return [
            'id'            => $lecture->id,
            'title'         => $lecture->title,
            'slug'          => $lecture->slug,
            'type'          => $lecture->type,
            'content_url'   => $lecture->type === 'file' ? FileService::getFileUrl($lecture->getRawOriginal('file')) : $lecture->youtube_url,
            'youtube_url'   => $lecture->youtube_url,
            'file'          => $lecture->type === 'file' ? $lecture->getRawOriginal('file') : null,
            'file_url'      => $lecture->type === 'file' ? FileService::getFileUrl($lecture->getRawOriginal('file')) : null,
            'file_extension'=> $lecture->file_extension,
            'hours'         => $lecture->hours,
            'minutes'       => $lecture->minutes,
            'seconds'       => $lecture->seconds,
            'duration'      => sprintf('%02d:%02d:%02d', $lecture->hours, $lecture->minutes, $lecture->seconds),
            'chapter_order' => $lecture->chapter_order,
            'is_active'     => $lecture->is_active,
            'free_preview'  => $lecture->free_preview,
            'materials'     => $lecture->resources->map(fn ($r) => [
                'id'    => $r->id,
                'title' => $r->title,
                'type'  => $r->type,
                'url'   => $r->type === 'url' ? $r->url : null,
                'file'  => $r->type === 'file' ? $r->file : null,
                'file_extension' => $r->file_extension,
                'order' => $r->order,
            ])->values()->toArray(),
        ];
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $course = Course::query()->find($id);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        // ── Validation ──────────────────────────────────────────────
        $maxVideoKb = (int) ini_get('upload_max_filesize') * 1024;

        $validator = Validator::make($request->all(), [
            'title'                         => 'required|string|min:2|max:255',
            'category_id'                   => 'required|exists:categories,id',
            'short_description'             => 'nullable|string',
            'description'                   => 'nullable|string',
            'level'                         => 'nullable|in:beginner,intermediate,advanced',
            'is_free'                       => 'nullable|boolean',
            'price'                         => 'nullable|numeric|min:0',
            'discount_price'                => 'nullable|numeric|min:0',
            'status'                        => 'nullable|in:draft,pending,publish',
            'instructor_id'                 => 'nullable|exists:users,id',
            'sequential_learning'           => 'nullable|boolean',
            'certificate_enabled'           => 'nullable|boolean',
            'certificate_fee'               => 'nullable|numeric|min:0',
            'thumbnail_url'                 => 'nullable|url',
            'thumbnail'                     => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            'promo_video_url'               => 'nullable|url',
            'intro_video'                   => "nullable|file|max:{$maxVideoKb}",
            'meta_title'                    => 'nullable|string|max:255',
            'meta_description'              => 'nullable|string',
            'meta_keywords'                 => 'nullable|string',
            'tags'                          => 'nullable|array',
            'tags.*'                        => 'nullable|string|max:255',
            'curriculum_sections'           => 'nullable|array',
            'curriculum_sections.*.title'   => 'required_with:curriculum_sections|string',
            'curriculum_sections.*.lessons' => 'nullable|array',
            'curriculum_sections.*.lessons.*.type' => 'nullable|in:video,file,youtube_url',
            'curriculum_sections.*.lessons.*.file' => 'nullable|file|max:51200',
            'curriculum_sections.*.lessons.*.materials'       => 'nullable|array',
            'curriculum_sections.*.lessons.*.materials.*.file' => 'nullable|file|max:51200',
            'standalone_lessons'            => 'nullable|array',
            'standalone_lessons.*.type'     => 'nullable|in:video,file,youtube_url',
            'standalone_lessons.*.file'     => 'nullable|file|max:51200',
            'standalone_lessons.*.materials'       => 'nullable|array',
            'standalone_lessons.*.materials.*.file' => 'nullable|file|max:51200',
            'is_featured'                   => 'nullable|boolean',
            'ai_knowledge_file'             => 'nullable|file|mimes:txt,md,csv,json,xml|max:5120',
            'remove_ai_knowledge'           => 'nullable|boolean',
            'chatbot_enabled'               => 'nullable|boolean',
            'chatbot_name'                  => 'nullable|string|max:100',
            'chatbot_welcome_message'       => 'nullable|string|max:500',
            'chatbot_system_prompt'         => 'nullable|string',
            'chatbot_max_tokens'            => 'nullable|integer|min:10|max:4000',
            'initial_views'                 => 'nullable|integer|min:0',
            'initial_students'              => 'nullable|integer|min:0',
            'initial_rating'                => 'nullable|numeric|min:0|max:5',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        // ── Build course data ───────────────────────────────────────
        $instructorId  = (int) ($request->input('instructor_id') ?? $course->user_id);
        $isFree        = $request->has('is_free') ? $request->boolean('is_free') : (bool) $course->is_free;
        $courseType    = $isFree ? 'free' : 'paid';
        $price         = $courseType === 'paid' ? round((float) $request->input('price', $course->price ?? 0), 2) : null;
        $discountPrice = $courseType === 'paid' && $request->filled('discount_price')
            ? round((float) $request->input('discount_price'), 2)
            : ($courseType === 'paid' ? $course->discount_price : null);

        $status = $request->input('status', $course->status);
        if (!in_array($status, ['draft', 'pending', 'publish'], true)) {
            $status = 'draft';
        }
        $approvalStatus = $status === 'publish' ? 'approved' : $course->approval_status;
        $isActive       = ($status === 'publish' && $approvalStatus === 'approved') ? 1 : ($request->has('is_active') ? $request->boolean('is_active') : $course->is_active);

        // Content structure determination
        $hasSections   = is_array($request->input('curriculum_sections')) && count($request->input('curriculum_sections')) > 0;
        $hasStandalone = is_array($request->input('standalone_lessons')) && count($request->input('standalone_lessons')) > 0;
        if ($hasSections || $hasStandalone) {
            $contentStructure = $hasSections ? 'chapters' : 'lessons';
        } else {
            $contentStructure = $course->content_structure;
        }

        $newTitle = trim((string) $request->input('title'));
        $slug = trim((string)$course->title) !== $newTitle
            ? HelperService::generateUniqueSlug(Course::class, $newTitle, $course->id)
            : $course->slug;

        // Thumbnail
        $thumbnail = $course->thumbnail;
        if ($request->hasFile('thumbnail')) {
            $thumbnail = FileService::compressAndUpload($request->file('thumbnail'), 'courses/thumbnail');
        } elseif ($request->filled('thumbnail_url')) {
            $thumbnail = $request->input('thumbnail_url');
        }

        // Intro video
        $introVideo = $course->intro_video;
        $introVideoType = $course->intro_video_type;
        if ($request->hasFile('intro_video')) {
            $introVideoType = 'file';
            $introVideo     = FileService::compressAndUpload($request->file('intro_video'), 'courses/intro_video');
        } elseif ($request->filled('promo_video_url')) {
            $introVideoType = 'url';
            $introVideo     = $request->input('promo_video_url');
        }

        $metaDescription = $request->input('meta_description') ?? $request->input('description') ?? $course->meta_description;
        $certificateFee  = $request->boolean('certificate_enabled')
            ? round((float) ($request->input('certificate_fee', $course->certificate_fee ?? 0)), 2)
            : null;

        // ── Persist ─────────────────────────────────────────────────
        try {
            DB::beginTransaction();

            $course->update([
                'title'              => $newTitle,
                'slug'               => $slug,
                'short_description'  => $request->input('short_description', $course->short_description),
                'meta_description'   => $metaDescription,
                'meta_title'         => $request->input('meta_title', $course->meta_title),
                'meta_keywords'      => $request->input('meta_keywords', $course->meta_keywords),
                'category_id'        => (int) $request->input('category_id', $course->category_id),
                'user_id'            => $instructorId,
                'level'              => $request->input('level', $course->level),
                'course_type'        => $courseType,
                'price'              => $price,
                'discount_price'     => $discountPrice,
                'status'             => $status,
                'approval_status'    => $approvalStatus,
                'is_active'          => $isActive,
                'is_free'            => $isFree,
                'sequential_access'  => $request->has('sequential_learning') ? $request->boolean('sequential_learning') : $course->sequential_access,
                'certificate_enabled' => $request->has('certificate_enabled') ? $request->boolean('certificate_enabled') : $course->certificate_enabled,
                'certificate_fee'    => $certificateFee,
                'thumbnail'          => $thumbnail,
                'intro_video'        => $introVideo,
                'intro_video_type'   => $introVideoType,
                'content_structure'  => $contentStructure,
                'is_featured'        => $request->has('is_featured') ? $request->boolean('is_featured') : $course->is_featured,
                'chatbot_enabled'    => $request->has('chatbot_enabled') ? $request->boolean('chatbot_enabled') : $course->chatbot_enabled,
                'chatbot_name'       => $request->input('chatbot_name', $course->chatbot_name),
                'chatbot_welcome_message' => $request->input('chatbot_welcome_message', $course->chatbot_welcome_message),
                'chatbot_system_prompt'   => $request->input('chatbot_system_prompt', $course->chatbot_system_prompt),
                'chatbot_max_tokens'      => $request->filled('chatbot_max_tokens') ? (int) $request->input('chatbot_max_tokens') : $course->chatbot_max_tokens,
                'initial_views'           => $request->filled('initial_views') ? (int) $request->input('initial_views') : $course->initial_views,
                'initial_students'        => $request->filled('initial_students') ? (int) $request->input('initial_students') : $course->initial_students,
                'initial_rating'          => $request->filled('initial_rating') ? (float) $request->input('initial_rating') : $course->initial_rating,
            ]);

            // AI Knowledge Base file for course chatbot
            if ($request->boolean('remove_ai_knowledge')) {
                // Admin wants to remove the knowledge file
                if ($course->ai_knowledge_file) {
                    FileService::delete($course->ai_knowledge_file);
                }
                $course->update([
                    'ai_knowledge_file' => null,
                    'ai_knowledge_content' => null,
                ]);
            } elseif ($request->hasFile('ai_knowledge_file')) {
                // Upload new knowledge file
                if ($course->ai_knowledge_file) {
                    FileService::delete($course->ai_knowledge_file);
                }
                $knowledgeFile = $request->file('ai_knowledge_file');
                $filePath = FileService::upload($knowledgeFile, 'courses/ai_knowledge');
                $fileContent = file_get_contents($knowledgeFile->getRealPath());
                $course->update([
                    'ai_knowledge_file' => $filePath,
                    'ai_knowledge_content' => $fileContent,
                ]);
            }

            // ── Tags ────────────────────────────────────────────────
            $tags = $request->input('tags', []);
            if (is_array($tags)) {
                $normalized = array_values(array_filter(array_map(
                    static fn ($t) => is_string($t) ? trim($t) : '',
                    $tags,
                )));
                if ($normalized !== []) {
                    $tagIds = HelperService::getOrStoreTagId($normalized);
                    $course->tags()->sync($tagIds);
                }
            }

            // ── Instructor sync ─────────────────────────────────────
            $instructors = HelperService::getInstructorsWithCourseRelatedPermissions([$instructorId]);
            if ($instructors->isNotEmpty()) {
                $course->instructors()->sync($instructors->pluck('id'));
            } else {
                $course->instructors()->sync([$instructorId]);
            }

            // ── Curriculum: Purge and Recreate ──────────────────────
            if ($request->has('curriculum_sections') || $request->has('standalone_lessons')) {
                // Delete old curriculum
                $chapterIds = $course->chapters()->pluck('id');
                $lectureIds = CourseChapterLecture::whereIn('course_chapter_id', $chapterIds)->pluck('id');

                LectureResource::whereIn('lecture_id', $lectureIds)->delete();
                CourseChapterLecture::whereIn('id', $lectureIds)->delete();
                $course->chapters()->delete();

                // Build new curriculum - Sections (chapters)
                $sections = $request->input('curriculum_sections', []);
                if (is_array($sections)) {
                    foreach ($sections as $sectionOrder => $section) {
                        $chapter = CourseChapter::create([
                            'course_id'     => $course->id,
                            'user_id'       => $instructorId,
                            'title'         => $section['title'] ?? 'Untitled Section',
                            'type'          => 'default',
                            'is_active'     => true,
                            'chapter_order' => $sectionOrder + 1,
                        ]);

                        $lessons = $section['lessons'] ?? [];
                        if (is_array($lessons)) {
                            foreach ($lessons as $lessonOrder => $lesson) {
                                $contentFile = $request->file("curriculum_sections.{$sectionOrder}.lessons.{$lessonOrder}.file");
                                $lecture = $this->buildLectureData($lesson, $chapter->id, $instructorId, $lessonOrder + 1, $contentFile);
                                $materialFiles = $request->file("curriculum_sections.{$sectionOrder}.lessons.{$lessonOrder}.materials") ?? [];
                                $this->buildLectureResources($lesson['materials'] ?? [], $materialFiles, $lecture->id, $instructorId);
                            }
                        }
                    }
                }

                // Build new curriculum - Standalone lessons
                $standaloneLessons = $request->input('standalone_lessons', []);
                if (is_array($standaloneLessons) && count($standaloneLessons) > 0) {
                    $defaultChapter = CourseChapter::create([
                        'course_id'     => $course->id,
                        'user_id'       => $instructorId,
                        'title'         => 'default',
                        'type'          => 'standalone',
                        'is_active'     => true,
                        'chapter_order' => count($sections) + 1,
                    ]);

                    foreach ($standaloneLessons as $lessonOrder => $lesson) {
                        $contentFile = $request->file("standalone_lessons.{$lessonOrder}.file");
                        $lecture = $this->buildLectureData($lesson, $defaultChapter->id, $instructorId, $lessonOrder + 1, $contentFile);
                        $materialFiles = $request->file("standalone_lessons.{$lessonOrder}.materials") ?? [];
                        $this->buildLectureResources($lesson['materials'] ?? [], $materialFiles, $lecture->id, $instructorId);
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to update course: ') . $e->getMessage(), 500);
        }

        $course->refresh()->load([
            'user', 'category', 'tags', 'instructors', 'language', 'learnings', 'requirements',
            'chapters' => fn ($q) => $q->orderBy('chapter_order'),
            'chapters.lectures' => fn ($q) => $q->orderBy('chapter_order'),
            'chapters.lectures.resources' => fn ($q) => $q->orderBy('order'),
        ]);

        return $this->jsonSuccess(__('Course updated successfully'), $this->buildCourseResponse($course));
    }

    public function approve(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $course = Course::find($id);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        $course->update(['approval_status' => 'approved']);
        return $this->jsonSuccess(__('Course approved'), $course->fresh());
    }

    public function reject(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $course = Course::find($id);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        $course->update(['approval_status' => 'rejected']);
        return $this->jsonSuccess(__('Course rejected'), $course->fresh());
    }

    public function restore(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $course = Course::onlyTrashed()->find($id);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        $course->restore();
        return $this->jsonSuccess(__('Course restored'), $course->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-delete');

        $course = Course::find($id);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        $course->delete();
        return $this->jsonSuccess(__('Course deleted'));
    }

    /**
     * Completely remove AI chatbot settings and knowledge base from a course.
     */
    public function removeAiInfo(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $course = Course::find($id);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        try {
            DB::beginTransaction();

            // Delete associated knowledge base file if exists
            if ($course->ai_knowledge_file) {
                FileService::delete($course->ai_knowledge_file);
            }

            // Clear all chatbot and AI related fields
            $course->update([
                'chatbot_enabled'         => false,
                'chatbot_name'            => null,
                'chatbot_welcome_message' => null,
                'chatbot_system_prompt'   => null,
                'chatbot_max_tokens'      => null,
                'ai_knowledge_file'       => null,
                'ai_knowledge_content'    => null,
            ]);

            DB::commit();

            return $this->jsonSuccess(
                __('Course AI information removed successfully'),
                $this->buildCourseResponse($course->fresh())
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to remove course AI info: ') . $e->getMessage(), 500);
        }
    }
}

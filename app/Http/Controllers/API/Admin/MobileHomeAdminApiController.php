<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\FeatureSection;
use App\Models\Slider;
use App\Models\Webinar;
use App\Services\ApiResponseService;
use App\Services\HelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class MobileHomeAdminApiController extends AdminCrudApiController
{
    private static bool $schemaChecked = false;

    private const ALLOWED_SECTION_TYPES = [
        'hero',
        'continue_learning',
        'subscription_promo',
        'featured_courses',
        'free_courses',
        'popular_courses',
        'top_rated_courses',
        'most_viewed_courses',
        'newly_added_courses',
        'courses',
        'categories',
        'podcasts',
        'top_rated_instructors',
        'offer',
        'my_learning',
        'recommend_for_you',
        'wishlist',
        'searching_based',
    ];

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Self-healing MySQL schema guard so ENUM truncation never happens even before migration runs.
     */
    private function ensureMobileSchemaReady(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            if (DB::getDriverName() === 'mysql') {
                $col = DB::selectOne("SHOW COLUMNS FROM feature_sections LIKE 'type'");
                if ($col && isset($col->Type) && str_starts_with(strtolower((string) $col->Type), 'enum')) {
                    DB::statement('ALTER TABLE feature_sections MODIFY COLUMN type VARCHAR(100) NOT NULL');
                }
            }
        } catch (Throwable) {
            // Ignore if lacks ALTER privilege; migration handles it
        }
    }

    /**
     * Format a FeatureSection model with normalized manual_course_ids and manual_courses.
     */
    private function formatSection(FeatureSection $section): array
    {
        $arr = $section->toArray();
        $manualCourses = $section->relationLoaded('manualCourses')
            ? $section->manualCourses->map(static fn($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'slug' => $c->slug ?? '',
                'thumbnail' => $c->thumbnail ?? '',
                'price' => (float) ($c->price ?? 0),
                'is_free' => (bool) ($c->is_free ?? false),
                'instructor_name' => $c->user->name ?? '',
            ])->values()->all()
            : [];

        $arr['manual_courses'] = $manualCourses;
        $arr['manual_course_ids'] = array_map(static fn($c) => (int) $c['id'], $manualCourses);
        $arr['layout'] = $section->layout ?? ($section->config['layout'] ?? 'carousel');
        $arr['audience'] = $section->audience ?? 'everyone';
        $arr['show_on_mobile'] = (bool) ($section->show_on_mobile ?? true);
        $arr['show_on_web'] = (bool) ($section->show_on_web ?? false);
        $arr['is_active'] = (bool) ($section->is_active ?? true);

        return $arr;
    }

    /**
     * Dashboard Overview metrics for Mobile Home CMS.
     */
    public function overview(): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        try {
            $totalSections = FeatureSection::where('show_on_mobile', true)->count();
            $activeSections = FeatureSection::where('show_on_mobile', true)->where('is_active', true)->count();
            $webOnlySections = FeatureSection::where(function ($q) {
                $q->where('show_on_mobile', false)->orWhereNull('show_on_mobile');
            })->count();

            $now = now();
            $activeBanners = Slider::where('is_active', true)
                ->where(fn($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
                ->where(fn($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now))
                ->count();

            $scheduledBanners = Slider::where('is_active', true)
                ->whereNotNull('start_at')
                ->where('start_at', '>', $now)
                ->count();

            $expiredBanners = Slider::where('is_active', true)
                ->whereNotNull('end_at')
                ->where('end_at', '<', $now)
                ->count();

            $disabledBanners = Slider::where('is_active', false)->count();

            $featuredCoursesCount = Course::where('is_active', 1)
                ->where('status', 'publish')
                ->where('approval_status', 'approved')
                ->where('is_featured', 1)
                ->count();

            $freeCoursesCount = Course::where('is_active', 1)
                ->where('status', 'publish')
                ->where('approval_status', 'approved')
                ->where(function ($q) {
                    $q->where('is_free', 1)
                      ->orWhere('course_type', 'free')
                      ->orWhereNull('price')
                      ->orWhere('price', 0);
                })
                ->count();

            $lastSectionUpdate = FeatureSection::where('show_on_mobile', true)->max('updated_at');
            $lastBannerUpdate = Slider::max('updated_at');
            $lastUpdated = max((string) ($lastSectionUpdate ?? ''), (string) ($lastBannerUpdate ?? '')) ?: now()->toIso8601String();

            return $this->jsonSuccess(__('Mobile Home overview retrieved successfully.'), [
                'status' => 'published',
                'active_sections_count' => $activeSections,
                'total_sections_count' => $totalSections,
                'web_only_sections_count' => $webOnlySections,
                'active_banners_count' => $activeBanners,
                'scheduled_banners_count' => $scheduledBanners,
                'expired_banners_count' => $expiredBanners,
                'disabled_banners_count' => $disabledBanners,
                'featured_courses_count' => $featuredCoursesCount,
                'free_courses_count' => $freeCoursesCount,
                'last_updated' => $lastUpdated,
            ]);
        } catch (Throwable $e) {
            return $this->jsonError(__('Failed to retrieve Mobile Home overview: ') . $e->getMessage(), 500);
        }
    }

    /**
     * Get all mobile home sections with their manual courses.
     * Supports ?include_web=1 to also return web-only sections that can be linked to mobile.
     */
    public function getSections(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $includeWeb = $request->boolean('include_web') || $request->query('scope') === 'all';

        $query = FeatureSection::with(['images', 'manualCourses.user', 'manualCourses.category'])
            ->when(!$includeWeb, static fn($q) => $q->where('show_on_mobile', true))
            ->orderByRaw('COALESCE(mobile_row_order, row_order, id) ASC');

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $sections = $query->get()->map(fn(FeatureSection $sec) => $this->formatSection($sec))->values();

        return $this->jsonSuccess(__('Mobile sections retrieved successfully.'), $sections);
    }

    /**
     * Seed canonical default mobile sections into database so admin can customize and reorder them.
     */
    public function seedDefaultSections(): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-create');
        $this->ensureMobileSchemaReady();

        $defaults = [
            ['type' => 'hero', 'title' => 'البانر الرئيسي', 'subtitle' => 'أبرز العروض والبانرات الترويجية', 'limit' => 5, 'audience' => 'everyone', 'layout' => 'carousel'],
            ['type' => 'continue_learning', 'title' => 'أكمل تعلمك', 'subtitle' => 'تابع من حيث توقفت', 'limit' => 5, 'audience' => 'authenticated', 'layout' => 'carousel'],
            ['type' => 'subscription_promo', 'title' => 'اشتراك Skillso الشامل', 'subtitle' => 'وصول غير محدود لجميع الدورات والمسارات والشهادات المعتمدة', 'limit' => 1, 'audience' => 'non_subscriber', 'layout' => 'card'],
            ['type' => 'featured_courses', 'title' => 'الدورات المميزة', 'subtitle' => 'اختياراتنا لك بعناية', 'limit' => 8, 'audience' => 'everyone', 'layout' => 'carousel'],
            ['type' => 'free_courses', 'title' => 'دورات مجانية', 'subtitle' => 'ابدأ رحلتك التعليمية مجاناً', 'limit' => 8, 'audience' => 'everyone', 'layout' => 'carousel'],
            ['type' => 'popular_courses', 'title' => 'الأعلى تقييماً والأكثر طلباً', 'subtitle' => 'دورات يفضلها آلاف المتعلمين', 'limit' => 8, 'audience' => 'everyone', 'layout' => 'carousel'],
            ['type' => 'newly_added_courses', 'title' => 'أحدث الدورات', 'subtitle' => 'محتوى جديد يضاف باستمرار', 'limit' => 8, 'audience' => 'everyone', 'layout' => 'carousel'],
            ['type' => 'categories', 'title' => 'تصفح الأقسام', 'subtitle' => 'اختر مجالك المفضل', 'limit' => 12, 'audience' => 'everyone', 'layout' => 'pills'],
            ['type' => 'podcasts', 'title' => 'بودكاست Skillso', 'subtitle' => 'استمع وتعلم في أي وقت', 'limit' => 4, 'audience' => 'everyone', 'layout' => 'card'],
            ['type' => 'top_rated_instructors', 'title' => 'نخبة المدربين', 'subtitle' => 'تعلم من خبراء المجال', 'limit' => 8, 'audience' => 'everyone', 'layout' => 'carousel'],
        ];

        $maxOrder = (int) (FeatureSection::where('show_on_mobile', true)->max('mobile_row_order') ?? 0);
        $createdOrEnabled = 0;

        foreach ($defaults as $idx => $def) {
            $existing = FeatureSection::where('type', $def['type'])
                ->where('show_on_mobile', true)
                ->first();

            if ($existing) {
                continue;
            }

            $maxOrder++;
            FeatureSection::create([
                'type' => $def['type'],
                'title' => $def['title'],
                'subtitle' => $def['subtitle'],
                'limit' => $def['limit'],
                'audience' => $def['audience'],
                'config' => ['layout' => $def['layout']],
                'row_order' => $maxOrder,
                'mobile_row_order' => $maxOrder,
                'is_active' => true,
                'show_on_mobile' => true,
                'show_on_web' => false,
                'visibility_devices' => ['mobile'],
            ]);
            $createdOrEnabled++;
        }

        $sections = FeatureSection::with(['images', 'manualCourses.user', 'manualCourses.category'])
            ->where('show_on_mobile', true)
            ->orderByRaw('COALESCE(mobile_row_order, row_order, id) ASC')
            ->get()
            ->map(fn(FeatureSection $sec) => $this->formatSection($sec))
            ->values();

        return $this->jsonSuccess(
            __('Default mobile sections initialized (:count added).', ['count' => $createdOrEnabled]),
            $sections
        );
    }

    /**
     * Store new mobile section.
     */
    public function storeSection(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-create');
        $this->ensureMobileSchemaReady();

        $allowedTypes = implode(',', self::ALLOWED_SECTION_TYPES);

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'type' => "required|in:{$allowedTypes}|max:100",
            'limit' => 'nullable|integer|min:1|max:50',
            'layout' => 'nullable|in:carousel,grid,pills,card',
            'audience' => 'nullable|in:everyone,guest,authenticated,subscriber,non_subscriber',
            'is_active' => 'nullable|boolean',
            'show_on_mobile' => 'nullable|boolean',
            'show_on_web' => 'nullable|boolean',
            'manual_courses' => 'nullable|array',
            'config' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $manualCourses = $data['manual_courses'] ?? [];
        $layout = $data['layout'] ?? ($data['config']['layout'] ?? 'carousel');
        unset($data['manual_courses'], $data['layout']);

        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $config['layout'] = $layout;
        $data['config'] = $config;

        $maxOrder = (int) (FeatureSection::max('mobile_row_order') ?? FeatureSection::max('row_order') ?? 0);
        $data['show_on_mobile'] = $request->has('show_on_mobile') ? $request->boolean('show_on_mobile') : true;
        $data['show_on_web'] = $request->boolean('show_on_web', false);
        $data['row_order'] = $maxOrder + 1;
        $data['mobile_row_order'] = $maxOrder + 1;
        $data['limit'] = $data['limit'] ?? 10;
        $data['audience'] = $data['audience'] ?? 'everyone';
        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;

        $section = FeatureSection::create($data);

        if (!empty($manualCourses)) {
            $this->syncManualCourses($section, $manualCourses);
        }

        $fresh = $section->fresh(['images', 'manualCourses.user', 'manualCourses.category']);

        return $this->jsonSuccess(
            __('Mobile section created successfully.'),
            $fresh ? $this->formatSection($fresh) : $section,
            201,
        );
    }

    /**
     * Update mobile section.
     */
    public function updateSection(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-edit');
        $this->ensureMobileSchemaReady();

        $section = FeatureSection::find($id);
        if (!$section) {
            return $this->jsonError(__('Section not found'), 404);
        }

        $allowedTypes = implode(',', self::ALLOWED_SECTION_TYPES);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'type' => "sometimes|required|in:{$allowedTypes}|max:100",
            'limit' => 'nullable|integer|min:1|max:50',
            'layout' => 'nullable|in:carousel,grid,pills,card',
            'audience' => 'nullable|in:everyone,guest,authenticated,subscriber,non_subscriber',
            'is_active' => 'nullable|boolean',
            'show_on_mobile' => 'nullable|boolean',
            'show_on_web' => 'nullable|boolean',
            'mobile_row_order' => 'nullable|integer',
            'manual_courses' => 'nullable|array',
            'config' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $manualCourses = array_key_exists('manual_courses', $data) ? $data['manual_courses'] : null;
        unset($data['manual_courses']);

        if (isset($data['layout'])) {
            $config = is_array($data['config'] ?? $section->config) ? ($data['config'] ?? $section->config) : [];
            $config['layout'] = $data['layout'];
            $data['config'] = $config;
            unset($data['layout']);
        }

        if (array_key_exists('show_on_mobile', $data) && $data['show_on_mobile'] && !$section->mobile_row_order) {
            $maxOrder = (int) (FeatureSection::max('mobile_row_order') ?? FeatureSection::max('row_order') ?? 0);
            $data['mobile_row_order'] = $maxOrder + 1;
        }

        $section->update($data);

        if ($manualCourses !== null) {
            $this->syncManualCourses($section, $manualCourses);
        }

        $fresh = $section->fresh(['images', 'manualCourses.user', 'manualCourses.category']);

        return $this->jsonSuccess(
            __('Mobile section updated successfully.'),
            $fresh ? $this->formatSection($fresh) : $section,
        );
    }

    /**
     * Reorder mobile sections.
     */
    public function reorderSections(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-edit');

        $validator = Validator::make($request->all(), [
            'orders' => 'required|array',
            'orders.*.id' => 'required|integer|exists:feature_sections,id',
            'orders.*.order' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        foreach ($request->input('orders') as $item) {
            FeatureSection::where('id', $item['id'])->update([
                'mobile_row_order' => $item['order'],
            ]);
        }

        return $this->jsonSuccess(__('Sections reordered successfully.'));
    }

    /**
     * Toggle or remove mobile section from mobile display.
     */
    public function deleteSection(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-delete');

        $section = FeatureSection::find($id);
        if (!$section) {
            return $this->jsonError(__('Section not found'), 404);
        }

        if ($section->show_on_web) {
            $section->update(['show_on_mobile' => false]);
        } else {
            $section->update(['show_on_mobile' => false]);
            $section->delete();
        }

        return $this->jsonSuccess(__('Mobile section removed successfully.'));
    }

    /**
     * Get all mobile banners.
     */
    public function getBanners(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $status = $request->query('status', 'all');
        $now = now();

        $orderExpr = DB::getDriverName() === 'mysql'
            ? 'CAST(`order` AS UNSIGNED) ASC'
            : 'CAST("order" AS INTEGER) ASC';
        $query = Slider::orderByRaw($orderExpr)->orderBy('created_at', 'desc');

        if ($status === 'active') {
            $query->where('is_active', true)
                ->where(fn($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
                ->where(fn($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now));
        } elseif ($status === 'scheduled') {
            $query->where('is_active', true)
                ->whereNotNull('start_at')
                ->where('start_at', '>', $now);
        } elseif ($status === 'expired') {
            $query->where('is_active', true)
                ->whereNotNull('end_at')
                ->where('end_at', '<', $now);
        } elseif ($status === 'disabled') {
            $query->where('is_active', false);
        }

        $banners = $query->get();

        return $this->jsonSuccess(__('Banners retrieved successfully.'), $banners);
    }

    /**
     * Store new mobile banner.
     */
    public function storeBanner(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-create');

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'image' => 'nullable|image|max:10240',
            'mobile_image' => 'nullable|image|max:10240',
            'image_url' => 'nullable|string|max:1000',
            'mobile_image_url' => 'nullable|string|max:1000',
            'cta_label' => 'nullable|string|max:100',
            'cta_type' => 'nullable|in:course,category,subscription_plans,search,podcast,webinar,approved_external_url,custom_link|max:50',
            'cta_target' => 'nullable|string|max:500',
            'audience' => 'nullable|in:everyone,guest,authenticated,subscriber,non_subscriber',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        unset($data['image_url'], $data['mobile_image_url']);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('sliders', 'public');
        } elseif ($request->filled('image_url')) {
            $data['image'] = $request->input('image_url');
        }

        if ($request->hasFile('mobile_image')) {
            $data['mobile_image'] = $request->file('mobile_image')->store('sliders/mobile', 'public');
        } elseif ($request->filled('mobile_image_url')) {
            $data['mobile_image'] = $request->input('mobile_image_url');
        }

        // Prevent MySQL NOT NULL constraint error if only mobile_image was provided
        $data['image'] = $data['image'] ?? $data['mobile_image'] ?? '';

        $maxOrder = (int) (Slider::pluck('order')->map(static fn($v) => (int) $v)->max() ?? 0);
        $data['order'] = (string) ($data['order'] ?? ($maxOrder + 1));
        $data['audience'] = $data['audience'] ?? 'everyone';
        $data['cta_type'] = $data['cta_type'] ?? 'custom_link';
        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;

        if (($data['cta_type'] === 'approved_external_url' || $data['cta_type'] === 'custom_link') && !empty($data['cta_target'])) {
            $data['third_party_link'] = $data['cta_target'];
        }

        $banner = Slider::create($data);

        return $this->jsonSuccess(__('Banner created successfully.'), $banner->fresh(), 201);
    }

    /**
     * Update banner.
     */
    public function updateBanner(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-edit');

        $banner = Slider::find($id);
        if (!$banner) {
            return $this->jsonError(__('Banner not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'image' => 'nullable|image|max:10240',
            'mobile_image' => 'nullable|image|max:10240',
            'image_url' => 'nullable|string|max:1000',
            'mobile_image_url' => 'nullable|string|max:1000',
            'cta_label' => 'nullable|string|max:100',
            'cta_type' => 'nullable|in:course,category,subscription_plans,search,podcast,webinar,approved_external_url,custom_link|max:50',
            'cta_target' => 'nullable|string|max:500',
            'audience' => 'nullable|in:everyone,guest,authenticated,subscriber,non_subscriber',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        unset($data['image_url'], $data['mobile_image_url']);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('sliders', 'public');
        } elseif ($request->filled('image_url')) {
            $data['image'] = $request->input('image_url');
        }

        if ($request->hasFile('mobile_image')) {
            $data['mobile_image'] = $request->file('mobile_image')->store('sliders/mobile', 'public');
        } elseif ($request->filled('mobile_image_url')) {
            $data['mobile_image'] = $request->input('mobile_image_url');
        }

        if (isset($data['order'])) {
            $data['order'] = (string) $data['order'];
        }

        if (isset($data['cta_type']) && ($data['cta_type'] === 'approved_external_url' || $data['cta_type'] === 'custom_link') && isset($data['cta_target'])) {
            $data['third_party_link'] = $data['cta_target'];
        }

        $banner->update($data);

        return $this->jsonSuccess(__('Banner updated successfully.'), $banner->fresh());
    }

    /**
     * Reorder mobile banners.
     */
    public function reorderBanners(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-edit');

        $validator = Validator::make($request->all(), [
            'orders' => 'required|array',
            'orders.*.id' => 'required|integer|exists:sliders,id',
            'orders.*.order' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        foreach ($request->input('orders') as $item) {
            Slider::where('id', $item['id'])->update([
                'order' => (string) $item['order'],
            ]);
        }

        return $this->jsonSuccess(__('Banners reordered successfully.'));
    }

    /**
     * Delete banner.
     */
    public function deleteBanner(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-delete');

        $banner = Slider::find($id);
        if (!$banner) {
            return $this->jsonError(__('Banner not found'), 404);
        }

        $banner->delete();

        return $this->jsonSuccess(__('Banner deleted successfully.'));
    }

    /**
     * Search entities (courses, categories, webinars) for CTA selection & manual course curation.
     */
    public function searchEntities(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $type = $request->query('type', 'course');
        $query = trim((string) $request->query('q', ''));

        if ($type === 'course') {
            $courses = Course::with('user')
                ->where('is_active', 1)
                ->where('status', 'publish')
                ->when($query !== '', fn($q) => $q->where(function ($sub) use ($query) {
                    $sub->where('title', 'LIKE', "%{$query}%")
                        ->orWhere('slug', 'LIKE', "%{$query}%");
                }))
                ->latest()
                ->take(25)
                ->get()
                ->map(static fn($c) => [
                    'id' => (string) $c->id,
                    'title' => $c->title,
                    'slug' => $c->slug ?? '',
                    'instructor' => $c->user->name ?? '',
                    'thumbnail' => $c->thumbnail ?? '',
                    'is_free' => (bool) $c->is_free,
                    'price' => (float) ($c->price ?? 0),
                ]);

            return $this->jsonSuccess(__('Courses found'), $courses);
        }

        if ($type === 'category') {
            $categories = Category::where('status', 1)
                ->when($query !== '', fn($q) => $q->where('name', 'LIKE', "%{$query}%"))
                ->take(25)
                ->get()
                ->map(static fn($cat) => [
                    'id' => (string) $cat->id,
                    'name' => $cat->name,
                    'title' => $cat->name,
                    'slug' => $cat->slug,
                    'image' => $cat->image ?? '',
                ]);

            return $this->jsonSuccess(__('Categories found'), $categories);
        }

        if ($type === 'webinar') {
            $webinars = Webinar::where('status', 'published')
                ->when($query !== '', fn($q) => $q->where('title', 'LIKE', "%{$query}%"))
                ->take(25)
                ->get()
                ->map(static fn($w) => [
                    'id' => (string) $w->id,
                    'title' => $w->title,
                    'slug' => $w->slug,
                ]);

            return $this->jsonSuccess(__('Webinars found'), $webinars);
        }

        return $this->jsonSuccess(__('Entities'), []);
    }

    /**
     * Live Mobile Home preview for Admin with audience simulator.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        /** @var \App\Http\Controllers\API\MobileHomeApiController $homeController */
        $homeController = app(\App\Http\Controllers\API\MobileHomeApiController::class);
        return $homeController->getHome($request);
    }

    private function syncManualCourses(FeatureSection $section, array $courseItems): void
    {
        $sync = [];
        foreach (array_values($courseItems) as $index => $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;
            if ($id) {
                $sync[(int) $id] = ['sort_order' => $index + 1];
            }
        }

        $section->manualCourses()->sync($sync);
    }
}

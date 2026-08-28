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
use App\Services\PricingCalculationService;
use App\Services\CourseProgressService;
use App\Services\UserEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class MobileHomeAdminApiController extends AdminCrudApiController
{
    public function __construct(
        private PricingCalculationService $pricingService,
        private CourseProgressService $progressService,
        private UserEnrollmentService $enrollmentService,
    ) {
        $this->middleware('auth:sanctum');
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

            $lastUpdated = FeatureSection::where('show_on_mobile', true)->max('updated_at') ?? now()->toDateTimeString();

            return $this->jsonSuccess(__('Mobile Home overview retrieved successfully.'), [
                'status' => 'published',
                'active_sections_count' => $activeSections,
                'total_sections_count' => $totalSections,
                'active_banners_count' => $activeBanners,
                'scheduled_banners_count' => $scheduledBanners,
                'expired_banners_count' => $expiredBanners,
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
     */
    public function getSections(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $query = FeatureSection::with(['images', 'manualCourses.user', 'manualCourses.category'])
            ->where('show_on_mobile', true)
            ->orderByRaw('COALESCE(mobile_row_order, row_order) ASC');

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $sections = $query->get();

        return $this->jsonSuccess(__('Mobile sections retrieved successfully.'), $sections);
    }

    /**
     * Store new mobile section.
     */
    public function storeSection(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-create');

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'type' => 'required|string|max:50',
            'limit' => 'nullable|integer|min:1|max:50',
            'layout' => 'nullable|in:carousel,grid,pills,card',
            'audience' => 'nullable|in:everyone,guest,authenticated,subscriber,non_subscriber',
            'is_active' => 'nullable|boolean',
            'manual_courses' => 'nullable|array',
            'config' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $manualCourses = $data['manual_courses'] ?? [];
        unset($data['manual_courses']);

        $maxOrder = FeatureSection::max('mobile_row_order') ?? FeatureSection::max('row_order') ?? 0;
        $data['show_on_mobile'] = true;
        $data['show_on_web'] = $request->boolean('show_on_web', false);
        $data['mobile_row_order'] = $maxOrder + 1;
        $data['limit'] = $data['limit'] ?? 10;
        $data['is_active'] = $request->boolean('is_active', true);

        $section = FeatureSection::create($data);

        if (!empty($manualCourses)) {
            $this->syncManualCourses($section, $manualCourses);
        }

        return $this->jsonSuccess(
            __('Mobile section created successfully.'),
            $section->fresh(['manualCourses']),
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

        $section = FeatureSection::find($id);
        if (!$section) {
            return $this->jsonError(__('Section not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'type' => 'sometimes|required|string|max:50',
            'limit' => 'nullable|integer|min:1|max:50',
            'layout' => 'nullable|in:carousel,grid,pills,card',
            'audience' => 'nullable|in:everyone,guest,authenticated,subscriber,non_subscriber',
            'is_active' => 'nullable|boolean',
            'manual_courses' => 'nullable|array',
            'config' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $manualCourses = $data['manual_courses'] ?? null;
        unset($data['manual_courses']);

        $section->update($data);

        if ($manualCourses !== null) {
            $this->syncManualCourses($section, $manualCourses);
        }

        return $this->jsonSuccess(
            __('Mobile section updated successfully.'),
            $section->fresh(['manualCourses']),
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

        $section->update(['show_on_mobile' => false]);

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

        $query = Slider::orderBy('order', 'asc')->orderBy('created_at', 'desc');

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
            'image_url' => 'nullable|string',
            'cta_label' => 'nullable|string|max:100',
            'cta_type' => 'nullable|string|max:50',
            'cta_target' => 'nullable|string|max:255',
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

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('sliders', 'public');
        } elseif ($request->filled('image_url')) {
            $data['image'] = $request->input('image_url');
        }

        if ($request->hasFile('mobile_image')) {
            $data['mobile_image'] = $request->file('mobile_image')->store('sliders/mobile', 'public');
        }

        $maxOrder = Slider::max('order') ?? 0;
        $data['order'] = $data['order'] ?? ($maxOrder + 1);
        $data['is_active'] = $request->boolean('is_active', true);

        $banner = Slider::create($data);

        return $this->jsonSuccess(__('Banner created successfully.'), $banner, 201);
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
            'image_url' => 'nullable|string',
            'cta_label' => 'nullable|string|max:100',
            'cta_type' => 'nullable|string|max:50',
            'cta_target' => 'nullable|string|max:255',
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

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('sliders', 'public');
        } elseif ($request->filled('image_url')) {
            $data['image'] = $request->input('image_url');
        }

        if ($request->hasFile('mobile_image')) {
            $data['mobile_image'] = $request->file('mobile_image')->store('sliders/mobile', 'public');
        }

        $banner->update($data);

        return $this->jsonSuccess(__('Banner updated successfully.'), $banner);
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
     * Live Preview simulator endpoint for Admin CMS.
     */
    public function getPreview(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $audience = $request->query('audience', 'guest');

        // Create a simulated controller call
        $homeApiController = new \App\Http\Controllers\API\MobileHomeApiController(
            $this->pricingService,
            $this->progressService,
            $this->enrollmentService,
        );

        return $homeApiController->getHome($request);
    }

    /**
     * Search entities (courses, categories, webinars) for CTA selection.
     */
    public function searchEntities(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('feature-sections-list');

        $type = $request->query('type', 'course');
        $query = $request->query('q', '');

        if ($type === 'course') {
            $courses = Course::with('user')
                ->where('is_active', 1)
                ->where('status', 'publish')
                ->when($query, fn($q) => $q->where('title', 'LIKE', "%{$query}%"))
                ->take(20)
                ->get()
                ->map(static fn($c) => [
                    'id' => (string) $c->id,
                    'title' => $c->title,
                    'instructor' => $c->user->name ?? '',
                    'thumbnail' => $c->thumbnail ?? '',
                    'is_free' => (bool) $c->is_free,
                    'price' => (float) ($c->price ?? 0),
                ]);

            return $this->jsonSuccess(__('Courses found'), $courses);
        }

        if ($type === 'category') {
            $categories = Category::where('status', 1)
                ->when($query, fn($q) => $q->where('name', 'LIKE', "%{$query}%"))
                ->take(20)
                ->get()
                ->map(static fn($cat) => [
                    'id' => (string) $cat->id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                ]);

            return $this->jsonSuccess(__('Categories found'), $categories);
        }

        if ($type === 'webinar') {
            $webinars = Webinar::where('status', 'published')
                ->when($query, fn($q) => $q->where('title', 'LIKE', "%{$query}%"))
                ->take(20)
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

    private function syncManualCourses(FeatureSection $section, array $courseItems): void
    {
        $sync = [];
        foreach ($courseItems as $index => $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;
            if ($id) {
                $sync[$id] = ['sort_order' => $index + 1];
            }
        }

        $section->manualCourses()->sync($sync);
    }
}

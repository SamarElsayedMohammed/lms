<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Course\Course;
use App\Models\FeatureSection;
use App\Models\Instructor;
use App\Models\Rating;
use App\Models\RefundRequest;
use App\Models\Slider;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CourseProgressService;
use App\Services\PricingCalculationService;
use App\Services\UserEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class MobileHomeApiController extends Controller
{
    public function __construct(
        private PricingCalculationService $pricingService,
        private CourseProgressService $progressService,
        private UserEnrollmentService $enrollmentService,
    ) {}

    /**
     * Canonical Mobile Home API endpoint.
     * Composes and returns CMS-ordered sections, dynamic banners, and audience-targeted content.
     */
    public function getHome(Request $request): JsonResponse
    {
        try {
            $user = Auth::guard('sanctum')->user() ?? Auth::user();
            $audienceState = $this->resolveAudienceState($user);

            // 1. Fetch Header Info
            $unreadNotifications = 0;
            if ($user) {
                try {
                    $unreadNotifications = \App\Models\UserNotification::where('user_id', $user->id)
                        ->where('is_read', false)
                        ->count();
                } catch (Throwable) {
                    $unreadNotifications = 0;
                }
            }

            $header = [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'profile' => $user->profile ?? '',
                ] : null,
                'audience_state' => $audienceState,
                'unread_notifications' => $unreadNotifications,
            ];

            // 2. Fetch Active Mobile Sections
            $configuredSections = FeatureSection::with('manualCourses')
                ->where('is_active', true)
                ->where('show_on_mobile', true)
                ->orderByRaw('COALESCE(mobile_row_order, row_order) ASC')
                ->get();

            // If no sections explicitly flagged for mobile yet, provide canonical default sequence
            if ($configuredSections->isEmpty()) {
                $configuredSections = $this->getDefaultMobileSections();
            }

            // 3. Resolve & Filter Sections
            $resolvedSections = [];
            foreach ($configuredSections as $section) {
                if (!$this->isAudienceAllowed($section->audience ?? 'everyone', $audienceState)) {
                    continue;
                }

                $sectionData = $this->resolveSectionData($section, $user, $audienceState, $request);

                // Omit empty sections
                if ($this->isSectionEmpty($section->type, $sectionData)) {
                    continue;
                }

                $resolvedSections[] = [
                    'id' => $section->id ?? 0,
                    'type' => $section->type,
                    'title' => $section->title ?? '',
                    'subtitle' => $section->subtitle ?? '',
                    'layout' => $section->layout ?? 'carousel',
                    'audience' => $section->audience ?? 'everyone',
                    'data' => $sectionData,
                ];
            }

            return response()->json([
                'status' => true,
                'success' => true,
                'message' => 'Mobile Home fetched successfully.',
                'data' => [
                    'header' => $header,
                    'sections' => $resolvedSections,
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('MobileHomeApiController -> getHome: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'success' => false,
                'message' => 'Failed to load Mobile Home data: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * Determine user audience state (guest, authenticated, subscriber, non_subscriber).
     */
    private function resolveAudienceState(?User $user): string
    {
        if (!$user) {
            return 'guest';
        }

        $isSubscriber = Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->exists();

        return $isSubscriber ? 'subscriber' : 'non_subscriber';
    }

    /**
     * Check if a section is allowed for the user's audience state.
     */
    private function isAudienceAllowed(string $sectionAudience, string $userAudience): bool
    {
        if ($sectionAudience === 'everyone') {
            return true;
        }

        if ($sectionAudience === 'guest') {
            return $userAudience === 'guest';
        }

        if ($sectionAudience === 'authenticated') {
            return $userAudience !== 'guest';
        }

        if ($sectionAudience === 'subscriber') {
            return $userAudience === 'subscriber';
        }

        if ($sectionAudience === 'non_subscriber') {
            return $userAudience === 'guest' || $userAudience === 'non_subscriber';
        }

        return true;
    }

    /**
     * Check if section data is empty and should be hidden.
     */
    private function isSectionEmpty(string $type, mixed $data): bool
    {
        if ($data === null) {
            return true;
        }

        if (is_array($data) && empty($data)) {
            return true;
        }

        if ($data instanceof \Illuminate\Support\Collection && $data->isEmpty()) {
            return true;
        }

        return false;
    }

    /**
     * Resolve content payload for a given section type.
     */
    private function resolveSectionData(FeatureSection $section, ?User $user, string $audienceState, Request $request): mixed
    {
        $limit = $section->limit > 0 ? $section->limit : 10;

        // If manual courses are curated on this section
        if ($section->manualCourses && $section->manualCourses->isNotEmpty()) {
            $courses = $section->manualCourses()
                ->with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                ->where('is_active', 1)
                ->where('status', 'publish')
                ->where('approval_status', 'approved')
                ->take($limit)
                ->get();

            return $this->transformCourses($courses, $user);
        }

        switch ($section->type) {
            case 'hero':
            case 'custom_banner':
            case 'offer':
                $banners = Slider::activeForMobile($user)
                    ->orderBy('order', 'asc')
                    ->take($limit)
                    ->get();

                return $banners->map(static fn(Slider $b) => [
                    'id' => $b->id,
                    'image' => $b->mobile_image_url ?: $b->image_url,
                    'title' => $b->title ?? '',
                    'subtitle' => $b->subtitle ?? '',
                    'cta_label' => $b->cta_label ?? 'استكشف الآن',
                    'cta_type' => $b->cta_type ?? 'custom_link',
                    'cta_target' => $b->cta_target ?? $b->third_party_link ?? '',
                ])->all();

            case 'continue_learning':
            case 'my_learning':
                if (!$user) {
                    return [];
                }

                $enrolled = $this->enrollmentService->resolveEnrolledCourses($user->id, static function ($q) {
                    $q->where('is_active', 1)->where('status', 'publish');
                });

                $items = [];
                foreach ($enrolled as $orderCourse) {
                    $course = $orderCourse->course;
                    if (!$course) continue;

                    $progress = $this->progressService->getProgressWithCache($user->id, $course->id);
                    if ($progress->progress_percentage > 0 && $progress->progress_percentage < 100) {
                        $items[] = [
                            'id' => (string) $course->id,
                            'title' => $course->title,
                            'thumbnail' => $course->thumbnail ?? '',
                            'progress' => (int) $progress->progress_percentage,
                            'last_accessed_at' => $progress->last_accessed_at?->toIso8601String(),
                        ];
                    }
                }

                return array_slice($items, 0, $limit);

            case 'featured_courses':
                $courses = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                    ->where('is_active', 1)
                    ->where('status', 'publish')
                    ->where('approval_status', 'approved')
                    ->where('is_featured', 1)
                    ->latest()
                    ->take($limit)
                    ->get();

                return $this->transformCourses($courses, $user);

            case 'free_courses':
                $courses = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                    ->where('is_active', 1)
                    ->where('status', 'publish')
                    ->where('approval_status', 'approved')
                    ->where(function ($q) {
                        $q->where('is_free', 1)
                          ->orWhere('course_type', 'free')
                          ->orWhereNull('price')
                          ->orWhere('price', 0);
                    })
                    ->latest()
                    ->take($limit)
                    ->get();

                return $this->transformCourses($courses, $user);

            case 'popular_courses':
            case 'top_rated_courses':
            case 'most_viewed_courses':
                $courses = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                    ->where('is_active', 1)
                    ->where('status', 'publish')
                    ->where('approval_status', 'approved')
                    ->withAvg(['ratings' => static fn($q) => $q->where('status', 'approved')], 'rating')
                    ->orderByDesc('ratings_avg_rating')
                    ->take($limit)
                    ->get();

                return $this->transformCourses($courses, $user);

            case 'newly_added_courses':
            case 'courses':
                $courses = Course::with(['user', 'category', 'taxes', 'ratings', 'wishlistedByUsers'])
                    ->where('is_active', 1)
                    ->where('status', 'publish')
                    ->where('approval_status', 'approved')
                    ->latest()
                    ->take($limit)
                    ->get();

                return $this->transformCourses($courses, $user);

            case 'categories':
                $categories = Category::where('status', 1)
                    ->select('categories.*')
                    ->take($limit)
                    ->get();

                return $categories->map(static fn($cat) => [
                    'id' => (string) $cat->id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                    'image' => $cat->image ?? '',
                    'courses_count' => (int) ($cat->courses_count ?? 0),
                ])->all();

            case 'subscription_promo':
                // Do not display promotional sell cards to active subscribers
                if ($audienceState === 'subscriber') {
                    return null;
                }

                return [
                    'title' => $section->title ?: 'اشتراك Skillso الشامل',
                    'subtitle' => $section->subtitle ?: 'وصول غير محدود لجميع الدورات والمسارات والشهادات المعتمدة',
                    'cta_label' => 'استكشف الباقات',
                    'target_route' => '/plans',
                ];

            case 'podcasts':
                return [
                    'title' => $section->title ?: 'بودكاست Skillso',
                    'subtitle' => $section->subtitle ?: 'استمع لحلقات تعليمية مختصرة ومفيدة في أي وقت',
                    'target_route' => '/podcasts',
                ];

            case 'top_rated_instructors':
            case 'instructors':
                $instructors = Instructor::with(['user', 'personal_details', 'ratings.user'])
                    ->where('status', 'approved')
                    ->take($limit)
                    ->get();

                return $instructors->map(static fn($inst) => [
                    'id' => (string) $inst->id,
                    'name' => $inst->user->name ?? '',
                    'slug' => $inst->user->slug ?? (string) $inst->id,
                    'title' => $inst->personal_details->qualification ?? $inst->type ?? 'مدرب معتمد',
                    'avatar_url' => $inst->user->profile ?? '',
                    'rating' => 5.0,
                    'courses_count' => (int) Course::where('user_id', $inst->user_id)->where('is_active', 1)->count(),
                ])->all();

            default:
                return [];
        }
    }

    /**
     * Transform courses collection to canonical payload with pricing and enrollment truth.
     */
    private function transformCourses($courses, ?User $user): array
    {
        return $courses->map(function ($course) use ($user) {
            $isWishlisted = false;
            $isEnrolled = false;

            if ($user) {
                $isWishlisted = $course->wishlistedByUsers->contains('id', $user->id);
                $isEnrolled = \App\Models\OrderCourse::whereHas('order', static fn($q) => $q->where('user_id', $user->id)->where('status', 'completed'))
                    ->where('course_id', $course->id)
                    ->exists();

                if ($isEnrolled) {
                    $hasRefund = RefundRequest::where('user_id', $user->id)
                        ->where('course_id', $course->id)
                        ->where('status', 'approved')
                        ->exists();
                    if ($hasRefund) {
                        $isEnrolled = false;
                    }
                }
            }

            $pricing = $this->pricingService->calculateCoursePricing($course);

            $isFree = (bool) ($course->is_free || $course->course_type === 'free' || ($course->price !== null && (float) $course->price === 0.0));

            return [
                'id' => (string) $course->id,
                'slug' => $course->slug,
                'title' => $course->title,
                'short_description' => $course->short_description ?? '',
                'thumbnail' => $course->thumbnail ?? '',
                'instructor' => $course->user->name ?? 'مدرب Skillso',
                'category_name' => $course->category->name ?? null,
                'course_type' => $course->course_type ?? ($isFree ? 'free' : 'paid'),
                'level' => $course->level ?? 'all_levels',
                'rating' => round((float) ($course->ratings_avg_rating ?? 0), 1),
                'students_count' => (int) ($course->total_enrolled ?? 0),
                'view_count' => (int) ($course->views_count ?? 0),
                'is_featured' => (bool) $course->is_featured,
                'is_free' => $isFree,
                'price_egp' => (float) ($pricing['price'] ?? $course->price ?? 0),
                'discount_price' => isset($pricing['discount_price']) && $pricing['discount_price'] !== null ? (float) $pricing['discount_price'] : null,
                'currency_symbol' => $pricing['currency_symbol'] ?? 'EGP',
                'discount_percentage' => (int) ($pricing['discount_percentage'] ?? 0),
                'is_wishlisted' => $isWishlisted,
                'is_enrolled' => $isEnrolled,
            ];
        })->all();
    }

    /**
     * Default canonical fallback mobile sections when no customized sections exist yet.
     */
    private function getDefaultMobileSections(): \Illuminate\Support\Collection
    {
        return collect([
            new FeatureSection([
                'id' => 1,
                'type' => 'hero',
                'title' => 'البانر الترويجي',
                'subtitle' => 'أبرز الفعاليات والدورات المميزة',
                'layout' => 'carousel',
                'limit' => 5,
                'audience' => 'everyone',
                'is_active' => true,
                'show_on_mobile' => true,
            ]),
            new FeatureSection([
                'id' => 2,
                'type' => 'continue_learning',
                'title' => 'تابع من حيث توقفت',
                'subtitle' => 'جلساتك الأخيرة محفوظة وجاهزة للاستكمال',
                'layout' => 'carousel',
                'limit' => 5,
                'audience' => 'authenticated',
                'is_active' => true,
                'show_on_mobile' => true,
            ]),
            new FeatureSection([
                'id' => 3,
                'type' => 'subscription_promo',
                'title' => 'اشتراك Skillso الشامل',
                'subtitle' => 'وصول غير محدود لجميع الدورات والمسارات والشهادات المعتمدة',
                'layout' => 'card',
                'limit' => 1,
                'audience' => 'non_subscriber',
                'is_active' => true,
                'show_on_mobile' => true,
            ]),
            new FeatureSection([
                'id' => 4,
                'type' => 'featured_courses',
                'title' => 'الدورات المميزة',
                'subtitle' => 'دورات مختارة بعناية لتطوير مسارك المهني',
                'layout' => 'carousel',
                'limit' => 10,
                'audience' => 'everyone',
                'is_active' => true,
                'show_on_mobile' => true,
            ]),
            new FeatureSection([
                'id' => 5,
                'type' => 'free_courses',
                'title' => '🎁 الدورات المجانية',
                'subtitle' => 'ابدأ التعلم الآن بدون أي تكلفة أو اشتراك',
                'layout' => 'carousel',
                'limit' => 10,
                'audience' => 'everyone',
                'is_active' => true,
                'show_on_mobile' => true,
            ]),
            new FeatureSection([
                'id' => 6,
                'type' => 'categories',
                'title' => 'التصنيفات الرئيسية',
                'subtitle' => 'اختر المجال المناسب لك بسرعة',
                'layout' => 'pills',
                'limit' => 12,
                'audience' => 'everyone',
                'is_active' => true,
                'show_on_mobile' => true,
            ]),
            new FeatureSection([
                'id' => 7,
                'type' => 'popular_courses',
                'title' => '🔥 الأكثر رواجاً',
                'subtitle' => 'الدورات الأكثر تسجيلاً وتفاعلاً من الطلاب',
                'layout' => 'carousel',
                'limit' => 10,
                'audience' => 'everyone',
                'is_active' => true,
                'show_on_mobile' => true,
            ]),
            new FeatureSection([
                'id' => 8,
                'type' => 'podcasts',
                'title' => 'بودكاست Skillso',
                'subtitle' => 'استمع لحلقات تعليمية مختصرة ومفيدة في أي وقت',
                'layout' => 'card',
                'limit' => 1,
                'audience' => 'everyone',
                'is_active' => true,
                'show_on_mobile' => true,
            ]),
            new FeatureSection([
                'id' => 9,
                'type' => 'top_rated_instructors',
                'title' => 'نخبة المدربين',
                'subtitle' => 'أفضل الخبراء المعتمدين لتوجيهك في رحلتك',
                'layout' => 'carousel',
                'limit' => 8,
                'audience' => 'everyone',
                'is_active' => true,
                'show_on_mobile' => true,
            ]),
        ]);
    }

    /**
     * Interaction tracking for impressions/clicks on mobile sections and banners.
     */
    public function interact(Request $request): JsonResponse
    {
        $interactions = $request->all();

        if (!is_array($interactions) || (count($interactions) > 0 && !is_array(reset($interactions)))) {
            return ApiResponseService::errorResponse('Payload must be an array of objects');
        }

        foreach ($interactions as $interaction) {
            if (isset($interaction['id'], $interaction['type']) && in_array($interaction['type'], ['view', 'click'])) {
                $id = (int) $interaction['id'];
                $type = $interaction['type'];
                $key = "mobile_feature_section:{$id}:{$type}s";
                try {
                    Redis::incr($key);
                } catch (Throwable) {
                    // Redis non-critical fallback
                }
            }
        }

        return ApiResponseService::successResponse('Interactions recorded.');
    }
}

const fs = require('fs');
const file = 'D:/Dev/skillso-main/backend-skillso/app/Http/Controllers/API/CourseApiController.php';
const content = fs.readFileSync(file, 'utf8');
const lines = content.split('\n');

const newMethod = `    public function getMyLearning(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "per_page" => "nullable|integer|min:1|max:100",
                "page" => "nullable|integer|min:1",
                "sort_by" => "nullable|in:id,title,created_at,updated_at,purchase_date",
                "sort_order" => "nullable|in:asc,desc",
                "search" => "nullable|string|max:255",
                "category_id" => "nullable|exists:categories,id",
                "level" => "nullable|string",
                "course_type" => "nullable|string|in:all,free,paid",
                "progress_status" => "nullable|in:all,in_progress,completed",
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $userId = Auth::user()?->id;

            // Get refund settings
            $refundEnabled = HelperService::systemSettings("refund_enabled") == 1;
            $refundPeriodDays = (int) HelperService::systemSettings("refund_period_days") ?? 7;

            $enrollmentService = app(UserEnrollmentService::class);
            $enrolledItems = $enrollmentService->resolveEnrolledCourseIds((int) $userId);

            if ($enrolledItems->isEmpty()) {
                return ApiResponseService::successResponse(
                    "My learning courses retrieved successfully",
                    new LengthAwarePaginator([], 0, $request->per_page ?? 15, $request->page ?? 1, [
                        "path" => request()->url(),
                        "pageName" => "page",
                    ])
                );
            }

            $courseIds = $enrolledItems->pluck('course_id')->toArray();
            $purchaseDatesMap = $enrolledItems->keyBy('course_id')->map(fn($i) => $i['purchase_date']);

            $query = Course::whereIn('id', $courseIds)
                ->where('status', 'publish')
                ->where('approval_status', 'approved')
                ->where('is_active', true);

            if ($request->filled("search")) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%")
                      ->orWhere('short_description', 'LIKE', "%{$search}%")
                      ->orWhere('level', 'LIKE', "%{$search}%")
                      ->orWhereHas('category', fn($cq) => $cq->where('name', 'LIKE', "%{$search}%"))
                      ->orWhereHas('user', fn($uq) => $uq->where('name', 'LIKE', "%{$search}%"));
                });
            }

            if ($request->filled("category_id")) {
                $query->where('category_id', $request->category_id);
            }

            if ($request->filled("level")) {
                $query->whereIn('level', explode(',', $request->level));
            }

            if ($request->filled("course_type") && $request->course_type !== 'all') {
                if ($request->course_type === 'free') {
                    $query->where('course_type', 'free');
                } else {
                    $query->where('course_type', '!=', 'free');
                }
            }

            // Sorting logic
            $sortBy = $request->sort_by ?? 'purchase_date';
            $sortOrder = $request->sort_order ?? 'desc';

            if ($sortBy === 'purchase_date') {
                // PHP-side sorting of the IDs based on the mapped purchase dates
                $sortedIds = $enrolledItems->sortBy(fn($item) => $item['purchase_date'], SORT_REGULAR, $sortOrder === 'desc')
                    ->pluck('course_id')->toArray();
                if (!empty($sortedIds)) {
                    $orderedIdsStr = implode(',', $sortedIds);
                    $query->orderByRaw("FIELD(id, {$orderedIdsStr})");
                }
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }

            // Pagination
            $perPage = $request->per_page ?? 15;
            $paginatedCourses = $query->paginate($perPage);

            // Fetch relations only for paginated items
            $paginatedIds = collect($paginatedCourses->items())->pluck('id')->toArray();
            
            if (empty($paginatedIds)) {
                return ApiResponseService::successResponse("My learning courses retrieved successfully", $paginatedCourses);
            }

            // We must hydrate the models properly for the transformations
            $hydratedQuery = Course::whereIn('id', $paginatedIds);
            $enrollmentService->applyMyLearningCourseEagerLoad($hydratedQuery);
            $hydratedCourses = $hydratedQuery->get()->keyBy('id');

            $wishlistedCourseIds = \App\Models\Wishlist::where('user_id', $userId)
                ->whereIn('course_id', $paginatedIds)
                ->pluck('course_id')
                ->toArray();

            $latestTrackings = \App\Models\UserCurriculumTracking::where('user_id', $userId)
                ->whereHas('chapter', function($q) use ($paginatedIds) { 
                    $q->whereIn('course_id', $paginatedIds); 
                })
                ->with('chapter')
                ->orderByDesc('completed_at')
                ->get()
                ->groupBy(function($item) {
                    return $item->chapter->course_id ?? 0;
                });

            $firstChapters = \App\Models\Course\CourseChapter\CourseChapter::whereIn('course_id', $paginatedIds)
                ->where('is_active', 1)
                ->orderBy('chapter_order')
                ->get()
                ->groupBy('course_id');

            // Map the paginated collection
            $transformedItems = collect($paginatedCourses->items())->map(function($basicCourse) use (
                $userId, $hydratedCourses, $purchaseDatesMap, $wishlistedCourseIds, $latestTrackings, $firstChapters, $refundEnabled, $refundPeriodDays
            ) {
                $course = $hydratedCourses->get($basicCourse->id);
                if (!$course) return null;

                $cachedProgress = app(\App\Services\CourseProgressService::class)->getProgressWithCache($userId, $course->id);
                $progressPercentage = (float) $cachedProgress->progress_percentage;
                $totalCurriculumItems = $cachedProgress->total_items;
                $completedCurriculumItems = $cachedProgress->completed_items;
                $startedCurriculumItems = $cachedProgress->status === 'not_started' ? 0 : max(1, $completedCurriculumItems);

                $currentChapterName = null;
                if ($completedCurriculumItems > 0) {
                    $lastTracking = $latestTrackings->get($course->id)?->first();
                    if ($lastTracking && $lastTracking->chapter) {
                        $currentChapterName = trim(preg_replace('/^Chapter\s+\d+:\s*/i', '', $lastTracking->chapter->title));
                    }
                } else {
                    $firstChapter = $firstChapters->get($course->id)?->first();
                    $currentChapterName = $firstChapter ? $firstChapter->title : null;
                }

                $orderDate = $purchaseDatesMap[$course->id] ?? null;

                $isRefundEligible = false;
                $refundDaysRemaining = 0;
                if ($refundEnabled && $orderDate && $course->course_type !== "free") {
                    $daysSincePurchase = now()->diffInDays($orderDate);
                    if ($daysSincePurchase <= $refundPeriodDays) {
                        $isRefundEligible = true;
                        $refundDaysRemaining = $refundPeriodDays - $daysSincePurchase;
                    }
                }

                $discountPercentage = 0;
                if ($course->display_price > 0 && $course->display_discount_price > 0 && $course->display_price > $course->display_discount_price) {
                    $discountPercentage = round((($course->display_price - $course->display_discount_price) / $course->display_price) * 100);
                }

                return [
                    "id" => $course->id,
                    "slug" => $course->slug,
                    "image" => $course->thumbnail,
                    "category_id" => $course->category->id ?? null,
                    "category_name" => $course->category->name ?? null,
                    "course_type" => $course->course_type,
                    "level" => $course->level,
                    "sequential_access" => $course->sequential_access ?? true,
                    "certificate_enabled" => $course->certificate_enabled ?? false,
                    "certificate_fee" => $course->certificate_fee ? (float) $course->certificate_fee : null,
                    "ratings" => $course->ratings_count ?? 0,
                    "average_rating" => round($course->ratings_avg_rating ?? 0, 2),
                    "title" => $course->title,
                    "short_description" => $course->short_description,
                    "author_id" => $course->user->id ?? null,
                    "author_name" => $course->user->name ?? null,
                    "author_slug" => $course->user->slug ?? null,
                    "price" => (float) $course->display_price,
                    "discount_price" => (float) $course->display_discount_price,
                    "total_tax_percentage" => (float) $course->total_tax_percentage,
                    "tax_amount" => (float) $course->tax_amount,
                    "discount_percentage" => $discountPercentage,
                    "is_wishlisted" => in_array($course->id, $wishlistedCourseIds),
                    "is_enrolled" => true,
                    "enrolled_at" => $course->created_at,
                    "total_chapters" => 0,
                    "completed_chapters" => 0,
                    "current_chapter_name" => $currentChapterName,
                    "total_curriculum_items" => $totalCurriculumItems,
                    "completed_curriculum_items" => $completedCurriculumItems,
                    "started_curriculum_items" => $startedCurriculumItems,
                    "progress_percentage" => $progressPercentage,
                    "progress_status" => $this->getProgressStatusWithStarted($progressPercentage, $startedCurriculumItems),
                    "refund_enabled" => $refundEnabled,
                    "refund_period_days" => $refundPeriodDays,
                    "is_refund_eligible" => $isRefundEligible,
                    "refund_days_remaining" => $refundDaysRemaining,
                    "purchase_date" => $orderDate ? $orderDate->format("Y-m-d H:i:s") : null,
                ];
            })->filter()->values();

            if ($request->filled("progress_status") && $request->progress_status !== "all") {
                $progressStatus = $request->progress_status;
                $transformedItems = $transformedItems->filter(function($course) use ($progressStatus) {
                    if ($progressStatus === "in_progress") {
                        return $course["progress_percentage"] > 0 && $course["progress_percentage"] < 100;
                    } elseif ($progressStatus === "completed") {
                        return $course["progress_percentage"] == 100;
                    }
                    return true;
                })->values();
            }

            // Update the paginator with the transformed items
            $paginatedCourses->setCollection($transformedItems);

            return ApiResponseService::successResponse(
                "My learning courses retrieved successfully",
                $paginatedCourses
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, "API Course Controller -> getMyLearning Method");
            return ApiResponseService::errorResponse("Failed to retrieve my learning courses.");
        }
    }`;

lines.splice(6746, 7142 - 6747 + 1, newMethod);
fs.writeFileSync(file, lines.join('\n'));
console.log('Successfully replaced getMyLearning!');

<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CourseFaq;
use App\Models\Course\Course;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;

/**
 * Public Course FAQ API Controller
 *
 * Returns active FAQs for a given course (no auth required).
 * Used on the course detail page to display common Q&A.
 */
class CourseFaqPublicApiController extends Controller
{
    /**
     * GET /api/courses/{courseId}/faqs
     */
    public function index(int $courseId): JsonResponse
    {
        $course = Course::find($courseId);

        if (! $course) {
            return response()->json([
                'status'  => false,
                'message' => __('Course not found'),
            ], 404);
        }

        $faqs = CourseFaq::where('course_id', $courseId)
            ->active()
            ->ordered()
            ->select('id', 'question', 'answer', 'sequence')
            ->get();

        return response()->json([
            'status'  => true,
            'message' => 'Course FAQs retrieved',
            'data'    => $faqs,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}

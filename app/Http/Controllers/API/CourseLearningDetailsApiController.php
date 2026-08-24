<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseLearning;
use App\Models\Course\CourseRequirement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CourseLearningDetailsApiController extends Controller
{
    public function update(Request $request, Course $course): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user?->hasAnyRole(['Super Admin', 'Supervisor', 'Staff'], 'web') ?? false;
        $canManageCourse = $isAdmin
            || (int) $course->user_id === (int) $user?->id
            || $course->instructors()->whereKey($user?->id)->exists();

        if (! $canManageCourse) {
            return response()->json(['success' => false, 'status' => false, 'message' => 'ليس لديك صلاحية تعديل هذه الدورة.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'learnings' => 'sometimes|array|max:100',
            'learnings.*' => 'required|string|max:65535',
            'requirements' => 'sometimes|array|max:100',
            'requirements.*' => 'required|string|max:65535',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();
        if (! array_key_exists('learnings', $data) && ! array_key_exists('requirements', $data)) {
            return response()->json(['success' => false, 'status' => false, 'message' => 'أرسل حقلاً واحدًا على الأقل للتحديث.'], 422);
        }

        DB::transaction(function () use ($course, $data): void {
            if (array_key_exists('learnings', $data)) {
                $course->learnings()->delete();
                foreach (array_values(array_filter(array_map('trim', $data['learnings']))) as $title) {
                    CourseLearning::create(['course_id' => $course->id, 'title' => $title]);
                }
            }

            if (array_key_exists('requirements', $data)) {
                $course->requirements()->delete();
                foreach (array_values(array_filter(array_map('trim', $data['requirements']))) as $requirement) {
                    CourseRequirement::create(['course_id' => $course->id, 'requirement' => $requirement]);
                }
            }
        });

        $course->load(['learnings:id,course_id,title', 'requirements:id,course_id,requirement']);

        return response()->json([
            'success' => true,
            'status' => true,
            'message' => 'تم حفظ تفاصيل الدورة بنجاح.',
            'data' => [
                'learnings' => $course->learnings->pluck('title')->values(),
                'requirements' => $course->requirements->pluck('requirement')->values(),
            ],
        ]);
    }
}

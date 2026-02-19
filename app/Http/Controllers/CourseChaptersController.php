<?php

namespace App\Http\Controllers;

use App\Http\Requests\CourseChapter\StoreCurriculumRequest;
use App\Http\Requests\CourseChapter\UpdateLectureCurriculumRequest;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Assignment\AssignmentResource;
use App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Lecture\LectureResource;
use App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz;
use App\Models\Course\CourseChapter\Quiz\QuizOption;
use App\Models\Course\CourseChapter\Quiz\QuizQuestion;
use App\Models\Course\CourseChapter\Quiz\QuizResource;
use App\Models\Course\CourseChapter\Resource\CourseChapterResource;
use App\Services\BootstrapTableService;
use App\Services\HelperService;
use App\Services\ResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CourseChaptersController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        /**** Check if User has any of the permissions ****/
        ResponseService::noAnyPermissionThenSendJson([
            'course-chapters-list',
            'course-chapters-create',
            'course-chapters-edit',
            'course-chapters-delete',
        ]);

        // Get only active courses created by Admin users
        // Fetch all courses (same as courses list page - no approval_status filter)
        $courses = Course::select('id', 'title')->orderBy('title', 'asc')->get();

        // Also fetch courses for filter dropdown (same as courses list)
        $coursesFilter = Course::select('id', 'title')->orderBy('title', 'asc')->get();

        $instructors = \App\Models\User::role(['Instructor', 'Admin'])->select('id', 'name')->get();

        return view('courses.chapters.index', compact('courses', 'instructors', 'coursesFilter'), [
            'type_menu' => 'course-chapters',
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        /**** Check if User has any of the permissions ****/
        ResponseService::noPermissionThenSendJson('course-chapters-create');
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id,deleted_at,NULL',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }
        try {
            $data = $validator->validated(); // get validated data
            $course = Course::findOrFail($data['course_id']);

            // Authorization check: Only course owner or approved team members can modify
            if (Auth::user()->cannot('modify', $course)) {
                return ResponseService::validationError(
                    'You are not authorized to add a chapter to this course. Only course owners and team members can modify course content.',
                );
            }

            $data['user_id'] = Auth::user()?->id; // get user id
            $data['is_active'] = 1; // auto active on create
            CourseChapter::create($data); // create chapter

            return ResponseService::successResponse('Chapter created successfully'); // return success response
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage()); // return error response
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        /**** Check if User has any of the permissions ****/
        ResponseService::noPermissionThenSendJson('course-chapters-list');

        // Get request data
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $showDeleted = request('show_deleted');
        $filterInstructorId = request('instructor_id');
        $filterCourseId = request('course_id');

        // Get course chapters - show all chapters, filter only if filters are applied
        $sql = CourseChapter::with(['course:id,title,user_id'])
            ->whereHas('course', static function ($q) use ($filterInstructorId, $filterCourseId): void {
                // Only apply filters if they are provided
                if (!empty($filterInstructorId)) {
                    $q->where('user_id', $filterInstructorId);
                }
                if (!empty($filterCourseId)) {
                    $q->where('id', $filterCourseId);
                }

                // If no filters, show all courses (no where clause)
            })
            ->when(!empty($search), static function ($query) use ($search): void {
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->where('id', 'LIKE', "%$search%")
                        ->orWhere('title', 'LIKE', "%$search%")
                        ->orWhereHas('course', static function ($query) use ($search): void {
                            $query->where('title', 'LIKE', "%$search%");
                        });
                });
            })
            ->when(!empty($showDeleted), static function ($query): void {
                $query->onlyTrashed();
            });

        // Get total count of course chapters
        $total = $sql->count();

        // Order and limit course chapters
        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        // Get bulk data
        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        // Loop through course chapters
        foreach ($res as $row) {
            $operate = '';
            if ($showDeleted) {
                if (auth()->user()->can('course-chapters-edit')) {
                    $operate .= BootstrapTableService::restoreButton(route('course-chapters.restore', $row->id));
                }
                if (auth()->user()->can('course-chapters-delete')) {
                    $operate .= BootstrapTableService::trashButton(route('course-chapters.trash', $row->id)); // permanent delete
                }
            } else {
                if (auth()->user()->can('course-chapters-list')) {
                    $operate .= BootstrapTableService::button(
                        'fas fa-plus',
                        route('course-chapters.curriculum.index', $row->id),
                        ['btn-info'],
                        ['title' => 'Add Curriculum'],
                    );
                }
                if (auth()->user()->can('course-chapters-edit')) {
                    $operate .= BootstrapTableService::editButton(route('course-chapters.update', $row->id), true);
                }
                if (auth()->user()->can('course-chapters-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('course-chapters.destroy', $row->id)); // soft delete
                }
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            // Add export column for is_active
            $tempRow['is_active_export'] =
                $tempRow['is_active'] == 1
                || $tempRow['is_active'] === 1
                || $tempRow['is_active'] === '1'
                || $tempRow['is_active'] === true
                    ? 'Active'
                    : 'Deactive';
            $tempRow['operate'] = $operate;
            // Ensure course_id is included in the response
            $tempRow['course_id'] = $row->course_id;
            // Ensure description is included in the response alongside title
            $tempRow['description'] = $row->description ?? null;

            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;

        return response()->json($bulkData);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        ResponseService::noPermissionThenRedirect('course-chapters-edit');

        $chapter = CourseChapter::with('course')->findOrFail($id);

        if (!$chapter->course) {
            return ResponseService::errorRedirectResponse('Course not found for this chapter');
        }

        $course = $chapter->course;

        return view('courses.chapters.edit', compact('chapter', 'course'), [
            'type_menu' => 'course-chapters',
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id = null)
    {
        /**** Check if User has any of the permissions ****/
        ResponseService::noPermissionThenSendJson('course-chapters-edit');

        $chapterId = $id ?? $request->id;

        $idValidator = Validator::make(['id' => $chapterId], [
            'id' => 'required|exists:course_chapters,id',
        ]);
        if ($idValidator->fails()) {
            return ResponseService::validationError($idValidator->errors()->first());
        }

        // Get chapter and course for authorization check
        $chapter = CourseChapter::with('course')->findOrFail($chapterId);

        // Authorization check: Only course owner or approved team members can modify
        if (Auth::user()->cannot('modify', $chapter->course)) {
            return ResponseService::validationError(
                'You are not authorized to update this chapter. Only course owners and team members can modify course content.',
            );
        }

        $validator = Validator::make($request->all(), [
            'course_id' => 'nullable|exists:courses,id,deleted_at,NULL',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $validator->validated();

            // Normalize is_active checkbox (auto true when checked, else false)
            $data['is_active'] = $request->has('is_active') ? 1 : 0;

            // Update course_id if provided
            if ($request->filled('course_id')) {
                $data['course_id'] = $request->course_id;
            }

            $chapter->update($data);

            return ResponseService::successResponse('Chapter updated successfully');
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        /**** Check if User has any of the permissions ****/
        ResponseService::noPermissionThenSendJson('course-chapters-delete');

        $validator = Validator::make(['id' => $id], [
            'id' => 'required|exists:course_chapters,id',
        ]);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        $chapter = CourseChapter::with('course')->findOrFail($id);

        // Authorization check: Only course owner or approved team members can delete
        if (Auth::user()->cannot('modify', $chapter->course)) {
            return ResponseService::validationError(
                'You are not authorized to delete this chapter. Only course owners and team members can modify course content.',
            );
        }

        try {
            $chapter->delete();

            return ResponseService::successResponse('Chapter deleted successfully');
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    /**
     * Restore a soft-deleted chapter.
     */
    public function restore(string $id)
    {
        /**** Check if User has any of the permissions ****/
        ResponseService::noPermissionThenSendJson('course-chapters-delete');

        try {
            $chapter = CourseChapter::onlyTrashed()->with('course')->findOrFail($id);

            // Authorization check: Only course owner or approved team members can restore
            if (Auth::user()->cannot('modify', $chapter->course)) {
                return ResponseService::validationError(
                    'You are not authorized to restore this chapter. Only course owners and team members can modify course content.',
                );
            }

            $chapter->restore();

            return ResponseService::successResponse('Chapter restored successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ResponseService::validationError('Chapter not found in trash');
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    /**
     * Permanently delete a soft-deleted chapter.
     */
    public function trash(string $id)
    {
        /**** Check if User has any of the permissions ****/
        ResponseService::noPermissionThenSendJson('course-chapters-delete');

        try {
            $chapter = CourseChapter::onlyTrashed()->with('course')->findOrFail($id);

            // Authorization check: Only course owner or approved team members can permanently delete
            if (Auth::user()->cannot('modify', $chapter->course)) {
                return ResponseService::validationError(
                    'You are not authorized to permanently delete this chapter. Only course owners and team members can modify course content.',
                );
            }

            $chapter->forceDelete();

            return ResponseService::successResponse('Chapter permanently deleted successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ResponseService::validationError('Chapter not found in trash');
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function curriculumIndex($id)
    {
        /**** Check if User has any of the permissions ****/
        ResponseService::noPermissionThenSendJson('course-chapters-list');
        $chapter = CourseChapter::where('id', $id)->with('course:id,title')->first();
        $allowedFileTypes = HelperService::getAllowedFileTypes();

        return view('courses.chapters.curriculums.index', compact('chapter', 'allowedFileTypes'), [
            'type_menu' => 'course-chapters',
        ]);
    }

    public function curriculumStore(StoreCurriculumRequest $request, $chapterId = null)
    {
        $chapterId ??= request('chapter_id');
        $idValidator = Validator::make(['id' => $chapterId], [
            'id' => 'required|exists:course_chapters,id',
        ]);
        if ($idValidator->fails()) {
            return ResponseService::validationError($idValidator->errors()->first());
        }

        // Get chapter and course for authorization check
        $chapter = CourseChapter::with('course')->findOrFail($chapterId);

        // Authorization check: Only course owner or approved team members can add curriculum
        if (Auth::user()->cannot('modify', $chapter->course)) {
            return ResponseService::validationError(
                'You are not authorized to add curriculum to this course. Only course owners and team members can modify course content.',
            );
        }

        try {
            DB::beginTransaction();
            $type = $request->type;

            // Validate type field
            if (!$type) {
                return ResponseService::validationError('The curriculum type is required.');
            }

            $validTypes = ['lecture', 'document', 'quiz', 'assignment'];
            if (!in_array($type, $validTypes)) {
                return ResponseService::validationError(
                    'The curriculum type must be one of: lecture, document, quiz, assignment.',
                );
            }

            $curriculumData = null;

            switch ($type) {
                case 'lecture':
                    $curriculumData = HelperService::updateAndGetLectureData($request, $chapterId); // update or create lecture
                    if ($request->resource_status == 1) {
                        HelperService::getTypeResourceData($type, $request, $curriculumData); // store resource data
                    }
                    break;
                case 'document':
                    $curriculumData = HelperService::updateAndGetDocumentData($request, $chapterId); // update or create document
                    break;
                case 'quiz':
                    $curriculumData = HelperService::updateAndGetQuizData(
                        $request,
                        $chapterId,
                        $request->qa_required ?? 1,
                    ); // update or create quiz
                    if ($request->resource_status == 1) {
                        HelperService::getTypeResourceData($type, $request, null, $curriculumData); // store resource data
                    }
                    break;
                case 'assignment':
                    $curriculumData = HelperService::updateAndGetAssignmentData($request, $chapterId); // update or create assignment
                    if ($request->resource_status == 1) {
                        HelperService::getTypeResourceData($type, $request, null, null, $curriculumData); // store resource data
                    }
                    break;
            }
            DB::commit();

            // Load resources relationship for curriculum types that support resources
            if ($curriculumData && in_array($type, ['lecture', 'quiz', 'assignment'])) {
                $curriculumData->load('resources');
            }

            // Prepare response data (remove resources from toArray to avoid confusion)
            $curriculumArray = $curriculumData ? $curriculumData->toArray() : null;
            if ($curriculumArray && isset($curriculumArray['resources'])) {
                unset($curriculumArray['resources']);
            }

            $responseData = [
                'curriculum' => $curriculumArray,
                'type' => $type,
                'chapter_id' => $chapterId,
            ];

            return ResponseService::successResponse('Curriculum created successfully', $responseData);
        } catch (Exception $e) {
            DB::rollBack();

            return ResponseService::errorResponse($e->getMessage()); // return error response
        }
    }

    public function getCurriculumDataList($chapterId = null)
    {
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $showDeleted = request('show_deleted');
        $chapterId ??= request('chapter_id');

        // Get the chapter
        $chapter = CourseChapter::findOrFail($chapterId);

        // Get all curriculum data
        $allCurriculumData = $chapter->all_curriculum_data;

        // Apply search filter if provided - search across all fields
        if (!empty($search)) {
            $allCurriculumData = $allCurriculumData->filter(static function ($curriculum) use ($search) {
                $searchLower = strtolower((string) $search);
                // Search in title
                $titleMatch = stripos($curriculum['title'] ?? '', (string) $search) !== false;
                // Search in type/curriculum_type
                $typeMatch = stripos($curriculum['curriculum_type'] ?? '', (string) $search) !== false;
                // Search in level
                $levelMatch = stripos($curriculum['level'] ?? '', (string) $search) !== false;
                // Search in course_type
                $courseTypeMatch = stripos($curriculum['course_type'] ?? '', (string) $search) !== false;
                // Search in instructor
                $instructorMatch = stripos($curriculum['instructor'] ?? '', (string) $search) !== false;
                // Search in duration (formatted)
                $durationMatch = stripos($curriculum['formatted_duration'] ?? '', (string) $search) !== false;
                // Search in resources (Yes/No)
                $resourcesText = !empty($curriculum['resources']) ? 'yes' : 'no';
                $resourcesMatch = stripos($resourcesText, $searchLower) !== false;
                // Search in status (Active/Inactive)
                $statusText = $curriculum['is_active'] ?? false ? 'active' : 'inactive';
                $statusMatch = stripos($statusText, $searchLower) !== false;
                // Search in ID
                $idMatch = stripos((string) ($curriculum['id'] ?? ''), (string) $search) !== false;

                return (
                    $titleMatch
                    || $typeMatch
                    || $levelMatch
                    || $courseTypeMatch
                    || $instructorMatch
                    || $durationMatch
                    || $resourcesMatch
                    || $statusMatch
                    || $idMatch
                );
            });
        }

        // Get total count before pagination
        $total = $allCurriculumData->count();

        // Always sort by chapter_order to maintain curriculum order
        // This ensures lectures, assignments, quizzes, and documents are displayed in the correct order
        // chapter_order is the primary sort to maintain the sequence of curriculum items
        $allCurriculumData = $allCurriculumData->sortBy(static fn($item) => $item['chapter_order'] ?? 999999)->values();

        // Apply pagination
        $paginatedData = $allCurriculumData->slice($offset, $limit)->values();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = $offset + 1; // Start numbering from offset + 1

        foreach ($paginatedData as $curriculum) {
            if ($showDeleted) {
                $operate = BootstrapTableService::restoreButton(route('course-chapters.curriculum.restore', [
                    'id' => $curriculum['id'],
                    'type' => $curriculum['curriculum_type'],
                ]));
                $operate .= BootstrapTableService::trashButton(route('course-chapters.trash', [
                    'id' => $curriculum['id'],
                    'type' => $curriculum['curriculum_type'],
                ])); // permanent delete
            } else {
                $operate = BootstrapTableService::editButton(route('course-chapters.curriculum.edit', [
                    'id' => $curriculum['id'],
                    'type' => $curriculum['curriculum_type'],
                ]), false);
                $operate .= BootstrapTableService::deleteButton(route('course-chapters.curriculum.destroy', [
                    'id' => $curriculum['id'],
                    'type' => $curriculum['curriculum_type'],
                ])); // soft delete
            }

            $tempRow['no'] = $no++;
            $tempRow['id'] = $curriculum['id'];
            $tempRow['title'] = $curriculum['title'];
            $tempRow['type'] = $curriculum['curriculum_type'];
            $tempRow['table_name'] = $curriculum['curriculum_type'];
            $tempRow['level'] = $curriculum['level'] ?? '';
            $tempRow['course_type'] = $curriculum['course_type'] ?? '';
            $tempRow['instructor'] = $curriculum['instructor'] ?? '';
            $tempRow['instructor_name'] = $curriculum['instructor'] ?? ''; // For table display
            $tempRow['duration'] = $curriculum['formatted_duration'];
            $tempRow['status'] = $curriculum['is_active'] ? true : false;
            $tempRow['is_active'] = $curriculum['is_active'] ?? false; // Boolean status
            $tempRow['status_text'] = $curriculum['is_active'] ?? false ? 'Active' : 'Inactive'; // Text status for display
            $tempRow['all_details'] = $curriculum;
            $tempRow['resources'] = !empty($curriculum['resources']) ? 1 : 0;
            $tempRow['particular_details_url'] = route('course-chapters.curriculum.particular-details', [
                $curriculum['id'],
                $curriculum['curriculum_type'],
            ]);
            $tempRow['update_status_url'] = route('course-chapters.curriculum.change-status', $curriculum['id']);
            $tempRow['restore_url'] = route('course-chapters.curriculum.restore', [
                $curriculum['id'],
                $curriculum['curriculum_type'],
            ]);
            $tempRow['operate'] = $operate;

            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;

        return response()->json($bulkData);
    }

    public function changeCurriculumStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            'type' => 'required',
            'status' => 'required|boolean',
        ]);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $validator->validated();
            switch ($data['type']) {
                case 'lecture':
                    $curriculum = CourseChapterLecture::with('chapter.course')->findOrFail($id);
                    break;
                case 'quiz':
                    $curriculum = CourseChapterQuiz::with('chapter.course')->findOrFail($id);
                    break;
                case 'resource':
                    $curriculum = CourseChapterResource::with('chapter.course')->findOrFail($id);
                    break;
                case 'assignment':
                    $curriculum = CourseChapterAssignment::with('chapter.course')->findOrFail($id);
                    break;
                default:
                    return ResponseService::errorResponse('Invalid curriculum type');
            }

            // Authorization check: Only course owner or approved team members can change status
            if (Auth::user()->cannot('modify', $curriculum->chapter->course)) {
                return ResponseService::validationError(
                    'You are not authorized to change the status of this curriculum item.',
                );
            }

            $curriculum->is_active = $data['status'];
            $curriculum->save();

            return ResponseService::successResponse('Status updated successfully');
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function getParticularCurriculumDetails($id = null, $type = null)
    {
        // Fallback to query parameters if route params are not passed
        $id ??= request('id');
        $type ??= request('type');

        if (!$id || !$type) {
            return ResponseService::errorResponse('Curriculum ID and type are required');
        }

        try {
            $curriculum = HelperService::getCurriculumData($type, $id);
            if ($curriculum) {
                return ResponseService::successResponse('Curriculum details fetched successfully', $curriculum);
            } else {
                return ResponseService::errorResponse('Curriculum not found');
            }
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function curriculumEdit($id, $type)
    {
        try {
            $curriculum = HelperService::getCurriculumData($type, $id);

            if (!$curriculum) {
                return ResponseService::errorRedirectResponse('Curriculum not found');
            }

            // Get the chapter and course for permission check
            $chapterId = $curriculum->course_chapter_id ?? null;

            if (!$chapterId) {
                return ResponseService::errorRedirectResponse('Chapter not found for this curriculum item');
            }

            $chapter = CourseChapter::with('course')->find($chapterId);

            if (!$chapter || !$chapter->course) {
                return ResponseService::errorRedirectResponse('Course not found for this curriculum item');
            }

            // Authorization check: Only course owner or approved team members can edit
            if (Auth::user()->cannot('modify', $chapter->course)) {
                return ResponseService::errorRedirectResponse('You are not authorized to edit this curriculum item. Only course owners and team members can modify course content.', route(
                    'course-chapters.curriculum.index',
                    $chapterId,
                ));
            }

            $allowedFileTypes = HelperService::getAllowedFileTypeCategories();

            return view('courses.chapters.curriculums.edit', compact('curriculum', 'allowedFileTypes'), [
                'type_menu' => 'course-chapters',
            ]);
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function quizQuestionsList($id = null)
    {
        $id ??= request('id');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'ASC');
        $search = request('search');
        $showDeleted = request('show_deleted');

        $sql = CourseChapterQuiz::where('id', $id)
            ->with('questions.options')
            ->when(!empty($search), static function ($query) use ($search): void {
                $query->where(static function ($q) use ($search): void {
                    $q->where('id', 'LIKE', "%$search%")->orWhereHas('questions', static function ($query) use (
                        $search,
                    ): void {
                        $query->where(
                            'question',
                            'LIKE',
                            "%$search%",
                        )->orWhereHas('options', static function ($query) use ($search): void {
                            $query->where('option', 'LIKE', "%$search%");
                        });
                    });
                });
            })
            ->when(!empty($showDeleted), static function ($query): void {
                $query->onlyTrashed();
            });

        $total = $sql->count();
        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->first();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        // Check if quiz exists before accessing questions
        if (!$res) {
            return response()->json([
                'total' => 0,
                'rows' => [],
                'message' => 'Quiz not found',
            ]);
        }

        foreach ($res->questions as $question) {
            $operate = BootstrapTableService::editButton(route('course-chapters.quiz.questions.update', [
                'id' => $question['id'],
            ]), true);
            $operate .= BootstrapTableService::deleteButton(route('course-chapters.quiz.questions.destroy', [
                'id' => $question['id'],
            ])); // soft delete
            // $operate .= BootstrapTableService::reorderButton(route('course-chapters.curriculum.reorder', array('id' => $question['id'], 'type' => 'questions')));
            $tempRow = $question->toArray();
            $tempRow['no'] = $no++;
            $tempRow['status'] = $question['is_active'] ? true : false;
            $tempRow['operate'] = $operate;

            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        if (request()->has('id')) {
            return ResponseService::successResponse('Quiz questions fetched successfully', $res ? $res->questions : []);
        }

        return response()->json($bulkData);
    }

    public function curriculumLectureUpdate(UpdateLectureCurriculumRequest $request, $chapterId = null)
    {
        $chapterId ??= request('chapter_id');

        // Validate chapter ID
        if (!$chapterId) {
            return ResponseService::validationError('Chapter ID is required.');
        }

        try {
            $lectureData = HelperService::updateAndGetLectureData($request, $chapterId); // update or create lecture
            if ($request->resource_status == 1) {
                HelperService::getTypeResourceData('lecture', $request, $lectureData); // update resource data
            } else {
                // If resource toggle is off, remove all existing lecture resources
                LectureResource::where('lecture_id', $lectureData->id)->delete();
            }

            // Load resources relationship
            $lectureData->load('resources');

            // Calculate resource statistics
            $lectureResources = $lectureData->resources ?? collect();
            $resourceStats = [
                'total_resources' => $lectureResources->count(),
                'file_resources' => $lectureResources->where('type', 'file')->count(),
                'url_resources' => $lectureResources->where('type', 'url')->count(),
                'youtube_resources' => $lectureResources->where('type', 'youtube_url')->count(),
            ];

            // Create curriculum data without relationships to avoid duplication
            $curriculumArray = $lectureData->toArray();
            unset($curriculumArray['resources']); // Remove duplicate resources

            $responseData = [
                'curriculum' => $curriculumArray,
                'type' => 'lecture',
                'chapter_id' => $chapterId,
                'resource_stats' => $resourceStats,
                'resources' => $lectureResources->map(static fn($resource) => [
                    'id' => $resource->id,
                    'lecture_id' => $resource->lecture_id,
                    'type' => $resource->type,
                    'file' => $resource->file,
                    'file_extension' => $resource->file_extension,
                    'url' => $resource->url,
                    'youtube_url' => $resource->youtube_url,
                    'title' => $resource->title ?? '',
                    'is_active' => $resource->is_active,
                    'order' => $resource->order,
                ]),
            ];

            return ResponseService::successResponse('Lecture updated successfully', $responseData);
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function curriculumQuizUpdate(Request $request, $chapterId = null)
    {
        $chapterId ??= request('chapter_id');

        // Validate chapter ID
        if (!$chapterId) {
            return ResponseService::validationError('Chapter ID is required.');
        }

        try {
            $quizData = HelperService::updateAndGetQuizData($request, $chapterId, $request->qa_required ?? 1); // update or create quiz); // update or create quiz
            if ($request->resource_status == 1) {
                HelperService::getTypeResourceData('quiz', $request, null, $quizData); // update resource data
            }

            // Load resources relationship
            $quizData->load('resources');

            // Calculate resource statistics
            $quizResources = $quizData->resources ?? collect();
            $resourceStats = [
                'total_resources' => $quizResources->count(),
                'file_resources' => $quizResources->where('type', 'file')->count(),
                'url_resources' => $quizResources->where('type', 'url')->count(),
            ];

            // Create curriculum data without relationships to avoid duplication
            $curriculumArray = $quizData->toArray();
            unset($curriculumArray['resources']); // Remove duplicate resources

            $responseData = [
                'curriculum' => $curriculumArray,
                'type' => 'quiz',
                'chapter_id' => $chapterId,
                'resource_stats' => $resourceStats,
                'resources' => $quizResources->map(static fn($resource) => [
                    'id' => $resource->id,
                    'quiz_id' => $resource->quiz_id,
                    'type' => $resource->type,
                    'file' => $resource->file,
                    'file_extension' => $resource->file_extension,
                    'url' => $resource->url,
                    'title' => $resource->title ?? '',
                    'is_active' => $resource->is_active,
                    'order' => $resource->order,
                ]),
            ];

            return ResponseService::successResponse('Quiz updated successfully', $responseData);
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function curriculumResourceUpdate(Request $request, $chapterId = null)
    {
        $chapterId ??= request('chapter_id');

        // Validate chapter ID
        if (!$chapterId) {
            return ResponseService::validationError('Chapter ID is required.');
        }

        try {
            $documentData = HelperService::updateAndGetDocumentData($request, $chapterId); // update or create resource
            if ($request->resource_status == 1) {
                HelperService::getTypeResourceData('resource', $request, null, $documentData); // update resource data
            }

            // Get chapter resources for context
            $chapterResources = CourseChapter::find($chapterId)
                ->resources()
                ->where('is_active', 1)
                ->get();

            // Calculate resource statistics
            $resourceStats = [
                'total_resources_in_chapter' => $chapterResources->count(),
                'document_resources' => $chapterResources->where('type', 'file')->count(),
                'url_resources' => $chapterResources->where('type', 'url')->count(),
                'current_resource_position' => $chapterResources->where('id', '<=', $documentData->id)->count(),
            ];

            $responseData = [
                'curriculum' => $documentData->toArray(),
                'type' => 'document',
                'chapter_id' => $chapterId,
                'resource_stats' => $resourceStats,
                'chapter_resources' => $chapterResources->map(static fn($resource) => [
                    'id' => $resource->id,
                    'title' => $resource->title,
                    'type' => $resource->type,
                    'file' => $resource->file,
                    'url' => $resource->url,
                    'is_active' => $resource->is_active,
                    'chapter_order' => $resource->chapter_order,
                ]),
            ];

            return ResponseService::successResponse('Resource updated successfully', $responseData);
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function curriculumAssignmentUpdate(Request $request, $chapterId = null)
    {
        $chapterId ??= request('chapter_id');

        // Validate chapter ID
        if (!$chapterId) {
            return ResponseService::validationError('Chapter ID is required.');
        }

        try {
            $assignmentData = HelperService::updateAndGetAssignmentData($request, $chapterId); // update or create Assignment
            if ($request->resource_status == 1) {
                HelperService::getTypeResourceData('assignment', $request, null, null, $assignmentData); // update Assignment data
            }

            // Load resources relationship
            $assignmentData->load('resources');

            // Calculate resource statistics
            $assignmentResources = $assignmentData->resources ?? collect();
            $resourceStats = [
                'total_resources' => $assignmentResources->count(),
                'file_resources' => $assignmentResources->where('type', 'file')->count(),
                'url_resources' => $assignmentResources->where('type', 'url')->count(),
            ];

            // Create curriculum data without relationships to avoid duplication
            $curriculumArray = $assignmentData->toArray();
            unset($curriculumArray['resources']); // Remove duplicate resources

            $responseData = [
                'curriculum' => $curriculumArray,
                'type' => 'assignment',
                'chapter_id' => $chapterId,
                'resource_stats' => $resourceStats,
                'resources' => $assignmentResources->map(static fn($resource) => [
                    'id' => $resource->id,
                    'assignment_id' => $resource->assignment_id,
                    'type' => $resource->type,
                    'file' => $resource->file,
                    'file_extension' => $resource->file_extension,
                    'url' => $resource->url,
                    'title' => $resource->title ?? '',
                    'is_active' => $resource->is_active,
                    'order' => $resource->order,
                ]),
            ];

            return ResponseService::successResponse('Assignment updated successfully', $responseData);
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function curriculumDestroy($id = null, $type = null)
    {
        $id ??= request('id');
        $type ??= request('type');

        if (!$id || !$type) {
            return ResponseService::errorResponse('Curriculum ID and type are required');
        }

        try {
            switch ($type) {
                case 'lecture':
                    $curriculum = CourseChapterLecture::with('chapter.course')->findOrFail($id);
                    break;
                case 'quiz':
                    $curriculum = CourseChapterQuiz::with('chapter.course')->findOrFail($id);
                    break;
                case 'document':
                    $curriculum = CourseChapterResource::with('chapter.course')->findOrFail($id);
                    break;
                case 'assignment':
                    $curriculum = CourseChapterAssignment::with('chapter.course')->findOrFail($id);
                    break;
                default:
                    return ResponseService::errorResponse('Invalid curriculum type');
            }

            // Authorization check: Only course owner or approved team members can delete
            if (Auth::user()->cannot('modify', $curriculum->chapter->course)) {
                return ResponseService::validationError('You are not authorized to delete this curriculum item.');
            }

            $curriculum->delete();

            return ResponseService::successResponse('Curriculum deleted successfully');
        } catch (\Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    /**
     * Get trashed curriculum list
     */
    public function getTrashedCurriculumList($chapterId = null)
    {
        $chapterId ??= request('chapter_id');

        if (!$chapterId) {
            return ResponseService::validationError('Chapter ID is required');
        }

        try {
            $trashedCurriculums = collect();

            // Get trashed lectures
            $trashedLectures = CourseChapterLecture::onlyTrashed()
                ->where('course_chapter_id', $chapterId)
                ->get()
                ->map(static function ($item) {
                    $item->curriculum_type = 'lecture';
                    $item->formatted_duration = HelperService::getFormattedDuration($item->duration ?? 0);
                    $item->free_preview = $item->free_preview ? true : false;

                    return $item;
                });
            $trashedCurriculums = $trashedCurriculums->merge($trashedLectures);

            // Get trashed quizzes
            $trashedQuizzes = CourseChapterQuiz::onlyTrashed()
                ->where('course_chapter_id', $chapterId)
                ->get()
                ->map(static function ($item) {
                    $item->curriculum_type = 'quiz';
                    $item->time_limit = HelperService::getFormattedDuration($item->time_limit ?? 0);

                    return $item;
                });
            $trashedCurriculums = $trashedCurriculums->merge($trashedQuizzes);

            // Get trashed assignments
            $trashedAssignments = CourseChapterAssignment::onlyTrashed()
                ->where('course_chapter_id', $chapterId)
                ->get()
                ->map(static function ($item) {
                    $item->curriculum_type = 'assignment';

                    return $item;
                });
            $trashedCurriculums = $trashedCurriculums->merge($trashedAssignments);

            // Get trashed documents
            $trashedDocuments = CourseChapterResource::onlyTrashed()
                ->where('course_chapter_id', $chapterId)
                ->get()
                ->map(static function ($item) {
                    $item->curriculum_type = 'document';
                    $item->formatted_duration = HelperService::getFormattedDuration($item->duration ?? 0);

                    return $item;
                });
            $trashedCurriculums = $trashedCurriculums->merge($trashedDocuments);

            return ResponseService::successResponse(
                'Trashed curriculum list fetched successfully',
                $trashedCurriculums->values(),
            );
        } catch (\Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    /**
     * Restore trashed curriculum
     */
    public function restoreCurriculum($id = null, $type = null)
    {
        $id ??= request('id');
        $type ??= request('type');

        if (!$id || !$type) {
            return ResponseService::errorResponse('Curriculum ID and type are required');
        }

        try {
            switch ($type) {
                case 'lecture':
                    $curriculum = CourseChapterLecture::withTrashed()->with('chapter.course')->findOrFail($id);
                    break;
                case 'quiz':
                    $curriculum = CourseChapterQuiz::withTrashed()->with('chapter.course')->findOrFail($id);
                    break;
                case 'document':
                    $curriculum = CourseChapterResource::withTrashed()->with('chapter.course')->findOrFail($id);
                    break;
                case 'assignment':
                    $curriculum = CourseChapterAssignment::withTrashed()->with('chapter.course')->findOrFail($id);
                    break;
                default:
                    return ResponseService::errorResponse('Invalid curriculum type');
            }

            // Authorization check: Only course owner or approved team members can restore
            if (Auth::user()->cannot('modify', $curriculum->chapter->course)) {
                return ResponseService::validationError('You are not authorized to restore this curriculum item.');
            }

            $curriculum->restore();

            return ResponseService::successResponse('Curriculum restored successfully');
        } catch (\Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function reorder($id, $type)
    {
        //  try {
        $curriculum = HelperService::getCurriculumData($type, $id);
        $allowedFileTypes = HelperService::getAllowedFileTypeCategories();
        if ($curriculum) {
            return view('courses.chapters.curriculums.reorder', compact('curriculum', 'allowedFileTypes'), [
                'type_menu' => 'course-chapters',
            ]);
        } else {
            return ResponseService::errorResponse('Curriculum not found');
        }
    }

    public function reorderUpdate(Request $request, $id, $type)
    {
        $validator = Validator::make($request->all(), [
            'order' => 'required|array',
            'order.*' => 'integer',
        ]);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        switch ($type) {
            case 'questions':
                $model = \App\Models\Course\CourseChapter\Quiz\QuizQuestion::class;
                break;
            case 'assignment_resources':
                $model = AssignmentResource::class;
                $foreignKey = 'assignment_id';
                break;
            case 'quiz_resources':
                $model = QuizResource::class;
                $foreignKey = 'quiz_id';
                break;
            case 'lecture_resources':
                $model = LectureResource::class;
                $foreignKey = 'lecture_id';
                break;
            default:
                return ResponseService::errorResponse('Invalid type');
        }

        foreach ($request->order as $index => $itemId) {
            $model::where('id', $itemId)->update(['order' => $index + 1]);
        }

        return ResponseService::successResponse('Items reordered successfully');
    }

    /**
     * Update curriculum order for all items in a chapter (using standard pattern like sliders)
     */
    public function updateRankOfCurriculum(Request $request, $chapterId = null)
    {
        /**** Check if User has any of the permissions ****/
        ResponseService::noPermissionThenSendJson('course-chapters-edit');

        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'chapter_id' => 'nullable|exists:course_chapters,id',
        ]);
        if ($validator->fails()) {
            return ResponseService::errorResponse($validator->errors()->first());
        }

        // Use route param if available, otherwise fallback to request->chapter_id
        $chapterId ??= $request->chapter_id;

        if (!$chapterId) {
            return ResponseService::errorResponse('Chapter ID is required');
        }

        // Get chapter and course for authorization check
        $chapter = CourseChapter::with('course')->findOrFail($chapterId);

        // Authorization check: Only course owner or approved team members can reorder
        if (Auth::user()->cannot('modify', $chapter->course)) {
            return ResponseService::validationError('You are not authorized to reorder curriculum items.');
        }

        try {
            // Get all curriculum items for this chapter
            $allCurriculum = $chapter->all_curriculum_data;

            foreach ($request->ids as $index => $id) {
                // Find the curriculum item by ID
                $curriculumItem = collect($allCurriculum)->firstWhere('id', $id);

                if ($curriculumItem) {
                    $model = null;

                    switch ($curriculumItem['curriculum_type']) {
                        case 'lecture':
                            $model = CourseChapterLecture::class;
                            break;
                        case 'quiz':
                            $model = CourseChapterQuiz::class;
                            break;
                        case 'assignment':
                            $model = CourseChapterAssignment::class;
                            break;
                        case 'document':
                            $model = CourseChapterResource::class;
                            break;
                    }

                    if ($model) {
                        $model::where('id', $id)
                            ->where('course_chapter_id', $chapterId)
                            ->update(['chapter_order' => $index + 1]);
                    }
                }
            }

            return ResponseService::successResponse('Curriculum items reordered successfully');
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function quizQuestionsStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'quiz_id' => 'required|exists:course_chapter_quizzes,id',
            'quiz_data' => 'required|array',
            'quiz_data.*.question' => 'required|string',
            'quiz_data.*.option_data' => 'required|array',
            'quiz_data.*.option_data.*.option' => 'required|string',
            'quiz_data.*.option_data.*.is_correct' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        $quizId = $request->quiz_id;

        // Get quiz and course for authorization check
        $quiz = CourseChapterQuiz::with('chapter.course')->findOrFail($quizId);

        // Authorization check: Only course owner or approved team members can add questions
        if (Auth::user()->cannot('modify', $quiz->chapter->course)) {
            return ResponseService::validationError('You are not authorized to add questions to this quiz.');
        }

        try {
            DB::beginTransaction();

            // Iterate through submitted quiz data
            foreach ($request->quiz_data as $index => $questionData) {
                // Create or update the question
                $question = QuizQuestion::create([
                    'user_id' => Auth::id(),
                    'course_chapter_quiz_id' => $quizId,
                    'question' => $questionData['question'],
                    'points' => 1.0, // Default points, can be made configurable
                    'order' => $index + 1,
                    'is_active' => true,
                ]);

                // Handle options/answers
                if (isset($questionData['option_data']) && is_array($questionData['option_data'])) {
                    foreach ($questionData['option_data'] as $optionIndex => $optionData) {
                        QuizOption::create([
                            'user_id' => Auth::id(),
                            'quiz_question_id' => $question->id,
                            'option' => $optionData['option'],
                            'is_correct' => (bool) $optionData['is_correct'],
                            'order' => $optionIndex + 1,
                            'is_active' => true,
                        ]);
                    }
                }
            }

            DB::commit();

            return ResponseService::successResponse('Quiz questions saved successfully');
        } catch (Exception $e) {
            DB::rollBack();

            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function quizQuestionsUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'quiz_id' => 'required|exists:course_chapter_quizzes,id',
            'quiz_data' => 'required|array',
            'quiz_data.*.question_id' => 'nullable|integer|exists:quiz_questions,id',
            'quiz_data.*.question' => 'required|string',
            'quiz_data.*.option_data' => 'required|array',
            'quiz_data.*.option_data.*.option_id' => 'nullable|integer|exists:quiz_options,id',
            'quiz_data.*.option_data.*.option' => 'required|string',
            'quiz_data.*.option_data.*.is_correct' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        $quizId = $request->quiz_id;

        // Get quiz and course for authorization check
        $quiz = CourseChapterQuiz::with('chapter.course')->findOrFail($quizId);

        // Authorization check: Only course owner or approved team members can update questions
        if (Auth::user()->cannot('modify', $quiz->chapter->course)) {
            return ResponseService::validationError('You are not authorized to update questions in this quiz.');
        }

        try {
            DB::beginTransaction();

            $questionCount = count($request->quiz_data);
            $perQuestionPoints = $quiz->total_points > 0 ? $quiz->total_points / $questionCount : 1.0;

            foreach ($request->quiz_data as $index => $questionData) {
                // Save or update question
                $question = QuizQuestion::updateOrCreate(['id' => $questionData['question_id'] ?? null], [
                    'user_id' => Auth::id(),
                    'course_chapter_quiz_id' => $quiz->id,
                    'question' => $questionData['question'],
                    'points' => $perQuestionPoints,
                    'order' => $index + 1,
                    'is_active' => true,
                ]);

                // Save or update options
                if (isset($questionData['option_data']) && is_array($questionData['option_data'])) {
                    // Get existing option IDs for this question
                    $existingOptionIds = $question->options()->pluck('id')->toArray();
                    $submittedOptionIds = [];

                    foreach ($questionData['option_data'] as $optionIndex => $optionData) {
                        $option = QuizOption::updateOrCreate(['id' => $optionData['option_id'] ?? null], [
                            'user_id' => Auth::id(),
                            'quiz_question_id' => $question->id,
                            'option' => $optionData['option'],
                            'is_correct' => (bool) $optionData['is_correct'],
                            'order' => $optionIndex + 1,
                            'is_active' => true,
                        ]);

                        // Collect submitted option IDs
                        if ($option->id) {
                            $submittedOptionIds[] = $option->id;
                        }
                    }

                    // Delete options that were not submitted (removed from frontend)
                    $optionsToDelete = array_diff($existingOptionIds, $submittedOptionIds);
                    if (!empty($optionsToDelete)) {
                        QuizOption::whereIn('id', $optionsToDelete)->delete();
                    }
                }
            }

            DB::commit();

            return ResponseService::successResponse('Quiz questions updated successfully', [
                'quiz_id' => $quiz->id,
                'questions' => $quiz->questions()->with('options')->get(),
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function quizQuestionGet(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'question_id' => 'required|exists:quiz_questions,id',
            ]);

            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }

            $question = QuizQuestion::where('id', $request->question_id)->where('is_active', true)->first();

            if (!$question) {
                return ResponseService::errorResponse('Question not found or inactive');
            }

            // Get options using the relationship (automatically excludes soft-deleted records)
            $options = $question->options()->where('is_active', true)->get();

            return ResponseService::successResponse('Question fetched successfully', [
                'question' => $question,
                'options' => $options->map(static fn($option) => [
                    'id' => $option->id,
                    'option' => $option->option,
                    'is_correct' => $option->is_correct,
                    'order' => $option->order,
                    'is_active' => $option->is_active,
                ]),
            ]);
        } catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function quizQuestionsDelete(Request $request)
    {
        // Get question with quiz and course for authorization check
        $question = QuizQuestion::with('quiz.chapter.course')->findOrFail($request->id);

        // Authorization check: Only course owner or approved team members can delete questions
        if (Auth::user()->cannot('modify', $question->quiz->chapter->course)) {
            return ResponseService::validationError('You are not authorized to delete this quiz question.');
        }

        try {
            DB::beginTransaction();

            // Delete related options first
            QuizOption::where('quiz_question_id', $question->id)->delete();

            // Delete the question itself
            $question->delete();

            DB::commit();

            return ResponseService::successResponse('Quiz question deleted successfully');
        } catch (Exception $e) {
            DB::rollBack();

            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function quizQuestionsBulkDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'question_ids' => 'required|array',
            'question_ids.*' => 'integer|exists:quiz_questions,id',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        // Get first question to check authorization (all questions should be from same quiz)
        $firstQuestion = QuizQuestion::with('quiz.chapter.course')->whereIn('id', $request->question_ids)->first();

        if (!$firstQuestion) {
            return ResponseService::validationError('No valid questions found.');
        }

        // Authorization check: Only course owner or approved team members can delete questions
        if (Auth::user()->cannot('modify', $firstQuestion->quiz->chapter->course)) {
            return ResponseService::validationError('You are not authorized to delete these quiz questions.');
        }

        try {
            DB::beginTransaction();

            $questions = QuizQuestion::whereIn('id', $request->question_ids)->get();

            foreach ($questions as $question) {
                QuizOption::where('quiz_question_id', $question->id)->delete();
                $question->delete();
            }

            DB::commit();

            return ResponseService::successResponse('Selected quiz questions deleted successfully');
        } catch (Exception $e) {
            DB::rollBack();

            return ResponseService::errorResponse($e->getMessage());
        }
    }
}

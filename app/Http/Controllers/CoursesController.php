<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\Course\CourseLanguage;
use App\Models\Course\CourseLearning;
use App\Models\Course\CourseRequirement;
use App\Models\Tag;
use App\Models\User;
use App\Services\BootstrapTableService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\InstructorModeService;
use App\Services\ResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CoursesController extends Controller
{
    private readonly string $uploadFolder;

    private readonly string $videoUploadFolder;

    private readonly string $metaImageUploadFolder;

    public function __construct()
    {
        $this->uploadFolder = 'courses/thumbnail';
        $this->videoUploadFolder = 'courses/intro_video';
        $this->metaImageUploadFolder = 'courses/meta_image';
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['courses-list', 'manage_courses']);
        $categories = HelperService::getActiveCategories();
        $tags = Tag::where('is_active', 1)->get();
        $course_languages = CourseLanguage::where('is_active', 1)->get();

        // In single instructor mode, don't show instructors for filtering
        $instructors = InstructorModeService::shouldShowInstructorFilters()
            ? User::role('Instructor')->get()
            : collect();

        return view('courses.index', compact('categories', 'tags', 'course_languages', 'instructors'), [
            'type_menu' => 'courses',
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        ResponseService::noPermissionThenRedirect('courses-create');
        $categories = HelperService::getActiveCategories();
        $tags = Tag::where('is_active', 1)->get();
        $course_languages = CourseLanguage::where('is_active', 1)->get();
        $instructors = InstructorModeService::shouldShowInstructorFilters()
            ? HelperService::getInstructorsWithCourseRelatedPermissions(null, true)
            : collect();

        // Get max video upload size from settings (in MB), default to 100MB for intro videos
        $maxVideoSize = HelperService::systemSettings('max_video_upload_size');
        $maxVideoSizeMB = !empty($maxVideoSize) ? (float) $maxVideoSize : 100;

        return view(
            'courses.create',
            compact('categories', 'tags', 'course_languages', 'instructors', 'maxVideoSizeMB'),
            ['type_menu' => 'courses'],
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // ResponseService::noPermissionThenSendJson('courses-create');

        // Get max video upload size from settings (in MB), default to 100MB for intro videos (backward compatibility)
        // Convert MB to KB for Laravel validation (max rule uses KB)
        $maxVideoSize = HelperService::systemSettings('max_video_upload_size');
        $maxSizeMB = !empty($maxVideoSize) ? (float) $maxVideoSize : 100;
        $maxSizeKB = $maxSizeMB * 1024;

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|min:2|max:255',
            'short_description' => 'nullable|string',
            'thumbnail' => 'required|file|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            'intro_video' => [
                'nullable',
                'file',
                'mimetypes:video/mp4,video/x-msvideo,video/avi,video/quicktime,video/webm',
                'max:' . $maxSizeKB,
                static function ($attribute, $value, $fail): void {
                    if ($value) {
                        $extension = strtolower((string) $value->getClientOriginalExtension());
                        $allowedExtensions = ['mp4', 'avi', 'mov', 'webm'];
                        if (!in_array($extension, $allowedExtensions)) {
                            $fail('The '
                            . $attribute
                            . ' must be a file of type: '
                            . implode(', ', $allowedExtensions)
                            . '.');
                        }
                    }
                },
            ],
            'level' => 'required|in:beginner,intermediate,advanced',
            'course_type' => 'required|in:free,paid',
            'status' => 'nullable|in:draft,pending,publish',
            'price' => 'required_if:course_type,paid|nullable|numeric|min:1',
            'discount_price' => 'nullable|numeric|min:0|lte:price',
            'category_id' => 'required|exists:categories,id',
            'is_active' => 'boolean',
            'sequential_access' => 'boolean',
            'certificate_enabled' => 'boolean',
            'certificate_fee' => 'required_if:certificate_enabled,1|nullable|numeric|min:0',
            'approval_status' => 'nullable|in:approved,rejected',
            'meta_title' => 'nullable|string|max:255',
            'meta_image' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            'meta_description' => 'nullable|string',
            'meta_keywords' => 'nullable|string',
            'course_tags' => 'nullable|array',
            'language_id' => 'required|exists:course_languages,id',
            'learnings_data' => 'required|array',
            'learnings_data.*.id' => 'nullable|exists:course_learnings,id',
            'learnings_data.*.learning' => 'required|string',
            'requirements_data' => 'required|array',
            'requirements_data.*.id' => 'nullable|exists:course_requirements,id',
            'requirements_data.*.requirement' => 'required|string',
            'instructors' => 'nullable|array',
            'instructors.*' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        // Check if price and discount_price are the same
        if ($request->course_type === 'paid' && $request->has('price') && $request->has('discount_price')) {
            $price = round((float) $request->price, 2);
            $discountPrice = round((float) $request->discount_price, 2);
            if ($price == $discountPrice && $discountPrice > 0) {
                return ResponseService::validationError('Price and discount price cannot be the same.');
            }
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated(); // Get Validated Data

            // Get User ID
            $staffUser = Auth::user()->hasRole('Staff');
            if ($staffUser) {
                $adminUser = User::role('Admin')->first();
                if ($adminUser) {
                    $userId = $adminUser->id;
                } else {
                    $userId = User::first()->id;
                }
            } else {
                $userId = Auth::user()?->id;
            }

            // Round Price and Discount Price to 2 decimal places
            $price = $request->has('price') ? round((float) $request->price, 2) : null;
            $discountPrice = $request->has('discount_price') ? round((float) $request->discount_price, 2) : null;
            $data['price'] = $request->course_type === 'free' ? null : $price;
            $data['discount_price'] = $request->course_type === 'free' ? null : $discountPrice;
            $data['user_id'] = $userId; // Set User ID
            $data['slug'] = HelperService::generateUniqueSlug(Course::class, $request->title); // Generate Unique Slug
            // Handle sequential_access - if checkbox is unchecked, it won't be sent, so default to 0
            $data['sequential_access'] = $request->has('sequential_access') ? ($request->sequential_access ? 1 : 0) : 0;
            $data['certificate_enabled'] = $request->certificate_enabled ?? 0; // Set Certificate Enabled (default: false)
            $data['certificate_fee'] = $request->certificate_enabled ? $request->certificate_fee ?? 0 : null; // Set Certificate Fee

            // Workflow: status and approval_status
            $isAdmin = Auth::user()->hasRole('Admin');
            $isInstructor = Auth::user()->hasRole('Instructor');
            $requestedStatus = $request->input('status');

            if ($isAdmin) {
                // Admin can set draft or publish directly; auto-approve
                $data['status'] = in_array($requestedStatus, ['draft', 'publish']) ? $requestedStatus : 'publish';
                $data['approval_status'] = $data['status'] === 'publish' ? 'approved' : null;
                // For admins, respect the explicit is_active toggle
                $data['is_active'] = $request->has('is_active') ? ($request->is_active ? 1 : 0) : 1;
            } elseif ($isInstructor) {
                // Instructor can draft or publish; publish becomes pending until admin approval
                if ($requestedStatus === 'publish') {
                    $data['status'] = 'pending';
                } else {
                    $data['status'] = 'draft';
                }
                $data['approval_status'] = null;
                // For instructors, derive is_active from status and approval
                $data['is_active'] = 0;
            } else {
                // Fallback
                $data['status'] = 'draft';
                $data['approval_status'] = null;
                $data['is_active'] = 0;
            }

            // Upload Thumbnail
            if ($request->hasFile('thumbnail')) {
                $data['thumbnail'] = FileService::compressAndUpload($request->file('thumbnail'), $this->uploadFolder);
            }

            // Upload Intro Video
            if ($request->hasFile('intro_video')) {
                $data['intro_video'] = FileService::compressAndUpload(
                    $request->file('intro_video'),
                    $this->videoUploadFolder,
                );
            }

            // Upload Meta Image
            if ($request->hasFile('meta_image')) {
                $data['meta_image'] = FileService::compressAndUpload(
                    $request->file('meta_image'),
                    $this->metaImageUploadFolder,
                );
            }

            // Ensure meta tags are saved
            $data['meta_title'] = $request->input('meta_title');
            $data['meta_description'] = $request->input('meta_description');
            $data['meta_keywords'] = $request->input('meta_keywords');

            // Create Course
            $course = Course::create($data);

            // Create Course Learnings
            if ($request->has('learnings_data') && !empty($request->learnings_data)) {
                $learningData = [];
                foreach ($request->learnings_data as $learning) {
                    $learningTitle = $learning['learning'] ?? '';
                    // Truncate to 65535 characters (TEXT field max length) as safety measure
                    if (strlen($learningTitle) > 65535) {
                        $learningTitle = substr($learningTitle, 0, 65535);
                    }
                    $learningData[] = [
                        'id' => $learning['id'] ?? '' ?: null,
                        'course_id' => $course->id,
                        'title' => $learningTitle,
                    ];
                }
                if (!empty($learningData)) {
                    CourseLearning::upsert($learningData, ['id']);
                }
            }

            // Create Course Requirements
            if ($request->has('requirements_data') && !empty($request->requirements_data)) {
                $requirementData = [];
                foreach ($request->requirements_data as $requirement) {
                    $requirementText = $requirement['requirement'] ?? '';
                    // Truncate to 65535 characters (TEXT field max length) as safety measure
                    if (strlen($requirementText) > 65535) {
                        $requirementText = substr($requirementText, 0, 65535);
                    }
                    $requirementData[] = [
                        'id' => $requirement['id'] ?? '' ?: null,
                        'course_id' => $course->id,
                        'requirement' => $requirementText,
                    ];
                }
                if (!empty($requirementData)) {
                    CourseRequirement::upsert($requirementData, ['id']);
                }
            }

            // Sync Tags
            if ($request->has('course_tags') && !empty($request->course_tags)) {
                $submittedTags = $request->input('course_tags', []);
                $tagIds = HelperService::getOrStoreTagId($submittedTags);
                $course->tags()->sync($tagIds);
            }

            // Sync Instructors
            // Priority: explicit instructors > admin override > single instructor mode
            $isAdmin = Auth::user()->hasRole('Admin');

            // First check if instructors were explicitly provided
            if ($request->has('instructors') && !empty($request->instructors)) {
                $instructorIds = $request->input('instructors');
                $instructors = HelperService::getInstructorsWithCourseRelatedPermissions($instructorIds);
                $instructorsIds = $instructors->pluck('id');
                // Sync Instructors
                $course->instructors()->sync($instructorsIds);
            } elseif ($isAdmin) {
                // Admin creates course without explicit instructors - assign admin as instructor
                $adminUsers = User::role(config('constants.SYSTEM_ROLES.ADMIN'))->get();
                if ($adminUsers->isNotEmpty()) {
                    $course->instructors()->sync($adminUsers->pluck('id'));
                }
            } elseif (InstructorModeService::isSingleInstructorMode()) {
                // In single instructor mode without explicit instructors, assign admin as instructor
                $adminUsers = User::role(config('constants.SYSTEM_ROLES.ADMIN'))->get();
                if ($adminUsers->isNotEmpty()) {
                    $course->instructors()->sync($adminUsers->pluck('id'));
                }
            }

            // Commit Transaction
            DB::commit();
            ResponseService::successResponse('Course Created Successfully', ['course_id' => $course->id]);
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Course Controller -> Store Method');
            ResponseService::errorResponse();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show()
    {
        ResponseService::noPermissionThenSendJson('courses-list');

        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $showDeleted = request('show_deleted');
        $filterStatus = request('status'); // optional: draft|pending|publish
        $filterIsActive = request('is_active'); // optional: 0|1
        $filterInstructorId = request('instructor_id'); // optional: creator user_id

        $sql = Course::with(['user', 'category', 'learnings', 'requirements', 'tags', 'language'])
            // Show all courses (approved, rejected, and pending) - removed approval_status filter
            ->when(!empty($search), static function ($query) use ($search): void {
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->where('title', 'LIKE', "%$search%")
                        ->orWhere('short_description', 'LIKE', "%$search%")
                        ->orWhere('level', 'LIKE', "%$search%");
                });
            })
            ->when(isset($filterIsActive) && $filterIsActive !== '', static function ($query) use (
                $filterIsActive,
            ): void {
                $query->where('is_active', (bool) $filterIsActive);
            })
            ->when(!empty($filterInstructorId), static function ($query) use ($filterInstructorId): void {
                $query->where('user_id', $filterInstructorId);
            })
            ->when(!empty($showDeleted), static function ($query): void {
                $query->onlyTrashed();
            });

        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        foreach ($res as $row) {
            $operate = '';
            if ($showDeleted) {
                if (auth()->user()->can('courses-restore')) {
                    $operate .= BootstrapTableService::restoreButton(route('courses.restore', $row->id));
                }
                if (auth()->user()->can('courses-trash')) {
                    $operate .= BootstrapTableService::trashButton(route('courses.trash', $row->id)); // permanent delete
                }
            } else {
                if (auth()->user()->can('courses-edit')) {
                    $operate .= BootstrapTableService::editButton(route('courses.edit', $row->id));
                }
                if (auth()->user()->can('courses-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('courses.destroy', $row->id)); // soft delete
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
            $tempRow['category'] = $row->category ? $row->category->name : null;

            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;

        return response()->json($bulkData);
    }

    /**
     * List pending course requests (instructor requested publish) for admin review.
     */
    public function requests()
    {
        ResponseService::noAnyPermissionThenRedirect(['courses-list', 'manage_courses']);
        $instructors = InstructorModeService::shouldShowInstructorFilters()
            ? User::role('Instructor')->get()
            : collect();

        return view('courses.requests', compact('instructors'), ['type_menu' => 'course-requests']);
    }

    public function requestsList()
    {
        ResponseService::noPermissionThenSendJson('courses-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $instructorId = request('instructor_id');

        $sql = Course::with(['user', 'category'])
            ->where('status', 'pending')
            ->whereNull('approval_status')
            ->when(!empty($search), static function ($query) use ($search): void {
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->where('title', 'LIKE', "%$search%")
                        ->orWhere('short_description', 'LIKE', "%$search%")
                        ->orWhereHas('user', static function ($uq) use ($search): void {
                            $uq->where('name', 'LIKE', "%$search%");
                        });
                });
            })
            ->when(!empty($instructorId), static function ($query) use ($instructorId): void {
                $query->where('user_id', $instructorId);
            });

        $total = $sql->count();
        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $rows = [];
        $no = 1;
        foreach ($res as $row) {
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['instructor_name'] = $row->user->name ?? null;
            $tempRow['operate'] = '';
            $rows[] = $tempRow;
        }

        return response()->json([
            'total' => $total,
            'rows' => $rows,
        ]);
    }

    public function rejected()
    {
        ResponseService::noAnyPermissionThenRedirect(['courses-list', 'manage_courses']);
        $instructors = InstructorModeService::shouldShowInstructorFilters()
            ? User::role('Instructor')->get()
            : collect();

        return view('courses.rejected', compact('instructors'), ['type_menu' => 'course-rejected']);
    }

    public function rejectedList()
    {
        ResponseService::noPermissionThenSendJson('courses-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $instructorId = request('instructor_id');

        $sql = Course::with(['user', 'category'])
            ->where('status', 'draft')
            ->where('approval_status', 'rejected')
            ->where('is_active', false)
            ->when(!empty($search), static function ($query) use ($search): void {
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->where('title', 'LIKE', "%$search%")
                        ->orWhere('short_description', 'LIKE', "%$search%")
                        ->orWhereHas('user', static function ($uq) use ($search): void {
                            $uq->where('name', 'LIKE', "%$search%");
                        });
                });
            })
            ->when(!empty($instructorId), static function ($query) use ($instructorId): void {
                $query->where('user_id', $instructorId);
            });

        $total = $sql->count();
        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $rows = [];
        $no = 1;
        foreach ($res as $row) {
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['instructor_name'] = $row->user->name ?? null;
            // Format created_at to readable date
            $tempRow['created_at'] = $row->created_at ? $row->created_at->format('M d, Y h:i A') : null;
            $tempRow['created_at_human'] = $row->created_at ? $row->created_at->diffForHumans() : null;
            // Add view button
            $tempRow['operate'] = '';
            $rows[] = $tempRow;
        }

        return response()->json([
            'total' => $total,
            'rows' => $rows,
        ]);
    }

    /**
     * Approve or decline a course request.
     * approve = 1 -> status publish, approval_status approved, is_active true
     * approve = 0 -> status draft, approval_status rejected, is_active false
     */
    public function approve(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('courses-edit');
        $validator = Validator::make(array_merge($request->all(), ['id' => $id]), [
            'id' => 'required|exists:courses,id',
            'approve' => 'required|in:0,1',
        ]);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $course = Course::findOrFail($id);
            $approve = (int) $request->approve === 1;

            if ($approve) {
                $course->status = 'publish';
                $course->approval_status = 'approved';
                $course->is_active = 1;
            } else {
                $course->status = 'draft';
                $course->approval_status = 'rejected';
                $course->is_active = 0;
            }

            $course->save();
            DB::commit();

            return ResponseService::successResponse('Course approval updated');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Course Controller -> Approve Method');

            return ResponseService::errorResponse();
        }
    }

    /**
     * Display the specified course (read-only view).
     */
    public function view($id)
    {
        ResponseService::noAnyPermissionThenRedirect(['courses-list', 'manage_courses']);
        $course = Course::with([
            'learnings',
            'requirements',
            'instructors',
            'category',
            'tags',
            'language',
            'chapters' => static function ($query): void {
                $query->orderBy('chapter_order', 'asc');
            },
            'chapters.lectures' => static function ($query): void {
                // Load all lectures (active and inactive) for admin view
                $query->orderBy('chapter_order', 'asc');
            },
            'chapters.lectures.resources' => static function ($query): void {
                // Load lecture resources
                $query->orderBy('order', 'asc');
            },
            'chapters.quizzes' => static function ($query): void {
                // Load all quizzes (active and inactive) for admin view
                $query->orderBy('chapter_order', 'asc');
            },
            'chapters.quizzes.questions' => static function ($query): void {
                // Load quiz questions
                $query->orderBy('order', 'asc');
            },
            'chapters.quizzes.questions.options' => static function ($query): void {
                // Load quiz question options (answers)
                $query->orderBy('order', 'asc');
            },
            'chapters.assignments' => static function ($query): void {
                // Load all assignments (active and inactive) for admin view
                $query->orderBy('chapter_order', 'asc');
            },
            'chapters.resources' => static function ($query): void {
                // Load all resources (active and inactive) for admin view
                $query->orderBy('chapter_order', 'asc');
            },
        ])->findOrFail($id);

        return view('courses.show', compact('course'), ['type_menu' => 'courses']);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        ResponseService::noPermissionThenRedirect('courses-edit');
        $course = Course::with(['learnings', 'requirements', 'instructors', 'category'])->findOrFail($id);

        // Handle missing category gracefully
        $parent_category_data = null;
        $parent_category = '';

        if ($course->category_id) {
            try {
                // Try to find category (including soft deleted)
                $parent_category_data = Category::withTrashed()->find($course->category_id);
                if ($parent_category_data) {
                    $parent_category = $parent_category_data->name ?? '';
                } else {
                    // Category was hard deleted, set to null
                    $parent_category = '';
                }
            } catch (\Exception) {
                // Category doesn't exist, handle gracefully
                $parent_category = '';
                $parent_category_data = null;
            }
        }

        $categories = Category::with('subcategories')->get();
        $tags = Tag::where('is_active', 1)->get();
        $course_languages = CourseLanguage::where('is_active', 1)->get();
        $shouldShowInstructorFilters = InstructorModeService::shouldShowInstructorFilters();
        $instructors = $shouldShowInstructorFilters
            ? User::role('Instructor')->where('is_active', 1)->get()
            : collect();

        // Get max video upload size from settings (in MB), default to 100MB for intro videos
        $maxVideoSize = HelperService::systemSettings('max_video_upload_size');
        $maxVideoSizeMB = !empty($maxVideoSize) ? (float) $maxVideoSize : 100;

        return view(
            'courses.edit',
            compact(
                'course',
                'categories',
                'parent_category_data',
                'parent_category',
                'tags',
                'course_languages',
                'instructors',
                'shouldShowInstructorFilters',
                'maxVideoSizeMB',
            ),
            ['type_menu' => 'courses'],
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        ResponseService::noPermissionThenSendJson('courses-edit');
        $idValidator = Validator::make(
            ['id' => $request->id],
            [
                'id' => 'required|exists:courses,id',
            ],
            [
                'id.exists' => 'The course id is not valid',
            ],
        );
        if ($idValidator->fails()) {
            return ResponseService::validationError($idValidator->errors()->first());
        }

        // Get Course Data early for authorization check
        $course = Course::findOrFail($request->input('id'));

        // Authorization check: Only course owner or approved team members can modify
        // Admins are NOT allowed to modify course content (they can only approve/reject)
        if (Auth::user()->cannot('modify', $course)) {
            return ResponseService::validationError(
                'You are not authorized to update this course. Only course owners and team members can modify course content.',
            );
        }

        // Get max video upload size from settings (in MB), default to 100MB for intro videos (backward compatibility)
        // Convert MB to KB for Laravel validation (max rule uses KB)
        $maxVideoSize = HelperService::systemSettings('max_video_upload_size');
        $maxSizeMB = !empty($maxVideoSize) ? (float) $maxVideoSize : 100;
        $maxSizeKB = $maxSizeMB * 1024;

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|min:2|max:255',
            'short_description' => 'nullable|string',
            'thumbnail' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            'intro_video' => [
                'nullable',
                'file',
                'mimetypes:video/mp4,video/x-msvideo,video/avi,video/quicktime,video/webm',
                'max:' . $maxSizeKB,
                static function ($attribute, $value, $fail): void {
                    if ($value) {
                        $extension = strtolower((string) $value->getClientOriginalExtension());
                        $allowedExtensions = ['mp4', 'avi', 'mov', 'webm'];
                        if (!in_array($extension, $allowedExtensions)) {
                            $fail('The '
                            . $attribute
                            . ' must be a file of type: '
                            . implode(', ', $allowedExtensions)
                            . '.');
                        }
                    }
                },
            ],
            'level' => 'required|in:beginner,intermediate,advanced',
            'status' => 'nullable|in:draft,pending,publish',
            'price' => 'required_if:course_type,paid|nullable|numeric|min:1',
            'discount_price' => 'nullable|numeric|min:0|lte:price',
            'is_active' => 'boolean',
            'sequential_access' => 'boolean',
            'certificate_enabled' => 'boolean',
            'certificate_fee' => 'required_if:certificate_enabled,1|nullable|numeric|min:0',
            'approval_status' => 'nullable|in:approved,rejected',
            'is_free' => 'boolean',
            'is_free_until' => 'nullable|date',
            'meta_title' => 'nullable|string|max:255',
            'meta_image' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            'meta_description' => 'nullable|string',
            'meta_keywords' => 'nullable|string',
            'language_id' => 'required|exists:course_languages,id',
            'learnings_data' => 'required|array',
            'learnings_data.*.id' => 'nullable|exists:course_learnings,id',
            'learnings_data.*.learning' => 'required|string',
            'requirements_data' => 'required|array',
            'requirements_data.*.id' => 'nullable|exists:course_requirements,id',
            'requirements_data.*.requirement' => 'required|string',
        ]);

        // Get course_type from request or existing course
        $courseType = $request->has('course_type') ? $request->course_type : $course->course_type;

        // Check if price and discount_price are the same
        if ($courseType === 'paid' && $request->has('price') && $request->has('discount_price')) {
            $price = round((float) $request->price, 2);
            $discountPrice = round((float) $request->discount_price, 2);
            if ($price == $discountPrice && $discountPrice > 0) {
                return ResponseService::validationError('Price and discount price cannot be the same.');
            }
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated(); // Get Validated Data

            // Round Price and Discount Price to 2 decimal places
            $price = $request->has('price') ? round((float) $request->price, 2) : null;
            $discountPrice = $request->has('discount_price') ? round((float) $request->discount_price, 2) : null;
            $data['price'] = $courseType === 'free' ? null : $price;
            $data['discount_price'] = $courseType === 'free' ? null : $discountPrice;

            // Handle sequential_access - if checkbox is unchecked, it won't be sent, so use existing value or default to 0
            $data['sequential_access'] = $request->has('sequential_access')
                ? ($request->sequential_access ? 1 : 0)
                : $course->sequential_access ?? 0;
            $data['certificate_enabled'] = $request->certificate_enabled ?? 0; // Set Certificate Enabled (default: false)
            $data['certificate_fee'] = $request->certificate_enabled ? $request->certificate_fee ?? 0 : null; // Set Certificate Fee

            $authUser = Auth::user();
            $isAdmin = $authUser->hasRole('Admin');

            // Delete old thumbnail and upload new one
            if ($request->hasFile('thumbnail')) {
                $data['thumbnail'] = FileService::compressAndReplace(
                    $request->file('thumbnail'),
                    $this->uploadFolder,
                    $course->thumbnail,
                );
            }

            // Delete old intro video and upload new one
            if ($request->hasFile('intro_video')) {
                $data['intro_video'] = FileService::compressAndReplace(
                    $request->file('intro_video'),
                    $this->videoUploadFolder,
                    $course->intro_video,
                );
            }

            // Delete old meta image and upload new one
            if ($request->hasFile('meta_image')) {
                $data['meta_image'] = FileService::compressAndReplace(
                    $request->file('meta_image'),
                    $this->metaImageUploadFolder,
                    $course->meta_image,
                );
            }

            // Ensure meta tags are saved
            $data['meta_title'] = $request->input('meta_title');
            $data['meta_description'] = $request->input('meta_description');
            $data['meta_keywords'] = $request->input('meta_keywords');

            // Workflow: status and is_approved on update
            // $isAdmin already defined above for authorization check
            $isInstructor = Auth::user()->hasRole('Instructor');
            $requestedStatus = $request->input('status');

            if ($isAdmin) {
                // Admin controls status and approval directly
                $data['status'] = in_array($requestedStatus, ['draft', 'publish'])
                    ? $requestedStatus
                    : $course->status ?? 'draft';
                // Respect explicit toggle if sent; default true when publishing
                $explicitApproval = $request->input('approval_status');
                if (in_array($explicitApproval, ['approved', 'rejected'])) {
                    $data['approval_status'] = $explicitApproval;
                } else {
                    $data['approval_status'] = $data['status'] === 'publish' ? 'approved' : null;
                }
                // For admins, respect the explicit is_active toggle
                $data['is_active'] = $request->has('is_active')
                    ? ($request->is_active ? 1 : 0)
                    : $course->is_active ?? 0;
            } elseif ($isInstructor) {
                // If course is not admin approved, instructor can set to draft or pending
                if ($course->approval_status !== 'approved') {
                    $data['status'] = in_array($requestedStatus, ['draft', 'pending'])
                        ? $requestedStatus
                        : $course->status;
                    // Only set approval_status to null if course is not approved
                    $data['approval_status'] = null;
                } else {
                    // If course is already admin approved (published and approved),
                    // instructor cannot change any status or anything.
                    // Preserve the approved status
                    $data['status'] = $course->status;
                    $data['approval_status'] = $course->approval_status;
                }

                // For instructors, derive is_active from status and approval
                $data['is_active'] = $data['status'] === 'publish' && $course->approval_status === 'approved' ? 1 : 0;
            } else {
                $data['status'] = $course->status;
                $data['approval_status'] = $course->approval_status;
                $data['is_active'] = $course->is_active ?? 0;
            }

            // Free course flags (subscription bypass)
            $data['is_free'] = $request->has('is_free') ? ($request->is_free ? 1 : 0) : ($course->is_free ?? 0);
            $data['is_free_until'] = $request->filled('is_free_until') ? $request->is_free_until : null;

            $course->update($data); // Update Course

            // Update Course Learnings
            if ($request->has('learnings_data') && !empty($request->learnings_data)) {
                $learningData = [];
                foreach ($request->learnings_data as $learning) {
                    $learningTitle = $learning['learning'] ?? '';
                    // Truncate to 65535 characters (TEXT field max length) as safety measure
                    if (strlen($learningTitle) > 65535) {
                        $learningTitle = substr($learningTitle, 0, 65535);
                    }
                    $learningData[] = [
                        'id' => $learning['id'] ?? '' ?: null,
                        'course_id' => $course->id,
                        'title' => $learningTitle,
                    ];
                }
                if (!empty($learningData)) {
                    CourseLearning::upsert($learningData, ['id']);
                }
            }

            // Update Course Requirements
            if ($request->has('requirements_data') && !empty($request->requirements_data)) {
                $requirementData = [];
                foreach ($request->requirements_data as $requirement) {
                    $requirementText = $requirement['requirement'] ?? '';
                    // Truncate to 65535 characters (TEXT field max length) as safety measure
                    if (strlen($requirementText) > 65535) {
                        $requirementText = substr($requirementText, 0, 65535);
                    }
                    $requirementData[] = [
                        'id' => $requirement['id'] ?? '' ?: null,
                        'course_id' => $course->id,
                        'requirement' => $requirementText,
                    ];
                }
                if (!empty($requirementData)) {
                    CourseRequirement::upsert($requirementData, ['id']);
                }
            }

            // Sync Tags
            if ($request->has('course_tags') && !empty($request->course_tags)) {
                $submittedTags = $request->input('course_tags', []); // Get Submitted Tags
                $tagIds = HelperService::getOrStoreTagId($submittedTags); // Get Tag IDs
                $course->tags()->sync($tagIds); // Sync Tags
            }

            // Sync Instructors
            // Priority: explicit instructors > admin override > single instructor mode
            // $isAdmin is already defined above for authorization check

            if ($request->has('instructors') && !empty($request->instructors)) {
                $instructorIds = $request->input('instructors', []);
                // Get Instructors with Course Related Permissions (same as store method)
                $instructors = HelperService::getInstructorsWithCourseRelatedPermissions($instructorIds);
                $instructorsIds = $instructors->pluck('id');
                // Sync Instructors
                $course->instructors()->sync($instructorsIds);
            } elseif ($isAdmin) {
                // Admin updates course without explicit instructors - assign admin as instructor
                $adminUsers = User::role(config('constants.SYSTEM_ROLES.ADMIN'))->get();
                if ($adminUsers->isNotEmpty()) {
                    $course->instructors()->sync($adminUsers->pluck('id'));
                }
            } elseif (InstructorModeService::isSingleInstructorMode()) {
                // In single instructor mode without explicit instructors, assign admin as instructor
                $adminUsers = User::role(config('constants.SYSTEM_ROLES.ADMIN'))->get();
                if ($adminUsers->isNotEmpty()) {
                    $course->instructors()->sync($adminUsers->pluck('id'));
                }
            }

            DB::commit();
            ResponseService::successResponse('Course Updated Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Course Controller -> Update Method');
            ResponseService::errorResponse();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('courses-delete');

        try {
            DB::beginTransaction();

            $course = Course::find($id);

            if (!$course) {
                return ResponseService::validationError('Course not found');
            }

            // Authorization check: Only course owner or approved team members can delete
            // Admins are NOT allowed to delete course content
            if (Auth::user()->cannot('modify', $course)) {
                return ResponseService::validationError(
                    'You are not authorized to delete this course. Only course owners and team members can delete courses.',
                );
            }

            $course->delete();

            DB::commit();

            return ResponseService::successResponse('Course Deleted Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Course Controller -> Destroy Method');

            return ResponseService::errorResponse();
        }
    }

    /**
     * Restore a soft-deleted course.
     */
    public function restore($id)
    {
        ResponseService::noPermissionThenSendJson('courses-delete');
        try {
            DB::beginTransaction();
            $course = Course::onlyTrashed()->findOrFail($id);
            $course->restore();
            DB::commit();
            ResponseService::successResponse('Course Restored Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Course Controller -> Restore Method');
            ResponseService::errorResponse();
        }
    }

    /**
     * Permanently delete a course from the database.
     */
    public function trash($id)
    {
        ResponseService::noPermissionThenSendJson('courses-delete');
        try {
            DB::beginTransaction();
            $course = Course::onlyTrashed()->findOrFail($id);

            FileService::delete($course->thumbnail);
            FileService::delete($course->intro_video);
            FileService::delete($course->meta_image);

            $course->forceDelete();
            DB::commit();
            ResponseService::successResponse('Course Permanently Deleted');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Course Controller -> Trash Method');
            ResponseService::errorResponse();
        }
    }

    // Remove Course Learnings
    public function destroyLearnings($id)
    {
        ResponseService::noPermissionThenSendJson('courses-create');

        // Validate Course ID
        $idValidator = Validator::make(
            ['id' => $id],
            [
                'id' => 'required|exists:course_learnings,id',
            ],
            [
                'id.exists' => 'The learning id is not valid',
            ],
        );
        if ($idValidator->fails()) {
            return ResponseService::validationError($idValidator->errors()->first());
        }

        try {
            CourseLearning::findOrFail($id)->delete();
            ResponseService::successResponse('Course Learning Deleted Successfully');
        } catch (Exception $th) {
            ResponseService::logErrorRedirect($th, 'Course Controller -> Destroy Learnings Method');
            ResponseService::errorResponse();
        }
    }

    // Remove Course Requirements
    public function destroyRequirements($id)
    {
        ResponseService::noPermissionThenSendJson('courses-create');

        // Validate Course ID
        $idValidator = Validator::make(
            ['id' => $id],
            [
                'id' => 'required|exists:course_requirements,id',
            ],
            [
                'id.exists' => 'The requirement id is not valid',
            ],
        );
        if ($idValidator->fails()) {
            return ResponseService::validationError($idValidator->errors()->first());
        }

        try {
            CourseRequirement::findOrFail($id)->delete();
            ResponseService::successResponse('Course Requirement Deleted Successfully');
        } catch (Exception $th) {
            ResponseService::logErrorRedirect($th, 'Course Controller -> Destroy Requirements Method');
            ResponseService::errorResponse();
        }
    }

    // Crud for Course Languages
    public function languagesIndex()
    {
        ResponseService::noAnyPermissionThenRedirect([
            'course-languages-list',
            'course-languages-create',
            'course-languages-edit',
            'course-languages-delete',
        ]);

        return view('courses.languages.index', ['type_menu' => 'course-languages']);
    }

    public function languagesStore(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['course-languages-create']);
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:255|unique:course_languages,name,null,id,deleted_at,NULL',
            'is_active' => 'boolean',
        ]);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $languageExists = CourseLanguage::where('name', $request->name)->withTrashed()->first();
            if ($languageExists) {
                if ($languageExists->deleted_at) {
                    $languageExists->restore();
                }
            } else {
                $data = $validator->validated();
                $data['slug'] = HelperService::generateUniqueSlug(CourseLanguage::class, $request->name);
                CourseLanguage::create($data);
            }
            DB::commit();
            ResponseService::successResponse('Language Created Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Languages Controller -> Store Method');
            ResponseService::errorResponse();
        }
    }

    public function languagesList(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['course-languages-list']);
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $showDeleted = request('show_deleted');

        $sql = CourseLanguage::query()
            ->when(!empty($search), static function ($query) use ($search): void {
                $query->where(static function ($q) use ($search): void {
                    $q->where('name', 'LIKE', "%$search%");
                });
            })
            ->when($showDeleted == '1', static function ($query): void {
                $query->onlyTrashed();
            });

        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        foreach ($res as $row) {
            $operate = '';
            if ($showDeleted) {
                if (auth()->user()->can('course-languages-edit')) {
                    $operate .= BootstrapTableService::restoreButton(route('courses.languages.restore', $row->id));
                }
                if (auth()->user()->can('course-languages-delete')) {
                    $operate .= BootstrapTableService::trashButton(route('courses.languages.trash', $row->id));
                }
            } else {
                if (auth()->user()->can('course-languages-edit')) {
                    $operate .= BootstrapTableService::editButton(
                        route('courses.languages.edit', $row->id),
                        true,
                        '#languageEditModal',
                        $row->id,
                    );
                }
                if (auth()->user()->can('course-languages-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('courses.languages.destroy', $row->id));
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

            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;

        return response()->json($bulkData);
    }

    public function languagesEdit(Request $request, $id)
    {
        ResponseService::noPermissionThenRedirect('course-languages-edit');
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:255|unique:course_languages,name,' . $id . ',id,deleted_at,NULL',
            'is_active' => 'boolean',
        ]);
        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $language = CourseLanguage::findOrFail($id);
            $data = $validator->validated();
            $data['slug'] = HelperService::generateUniqueSlug(CourseLanguage::class, $request->name, $id);
            $language->update($data);
            DB::commit();
            ResponseService::successResponse('Language Updated Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Languages Controller -> Update Method');
            ResponseService::errorResponse();
        }
    }

    public function languagesDestroy($id)
    {
        ResponseService::noPermissionThenRedirect(['course-languages-delete']);
        try {
            DB::beginTransaction();
            CourseLanguage::findOrFail($id)->delete();
            DB::commit();
            ResponseService::successResponse('Language Deleted Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Languages Controller -> Destroy Method');
            ResponseService::errorResponse();
        }
    }

    public function languagesRestore($id)
    {
        ResponseService::noPermissionThenRedirect(['course-languages-edit']);
        try {
            DB::beginTransaction();
            CourseLanguage::withTrashed()->findOrFail($id)->restore();
            DB::commit();
            ResponseService::successResponse('Language Restored Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Languages Controller -> Restore Method');
            ResponseService::errorResponse();
        }
    }

    public function languagesTrash($id)
    {
        ResponseService::noPermissionThenRedirect(['course-languages-delete']);
        try {
            DB::beginTransaction();
            CourseLanguage::withTrashed()->findOrFail($id)->forceDelete();
            DB::commit();
            ResponseService::successResponse('Language Permanently Deleted Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Languages Controller -> Trash Method');
            ResponseService::errorResponse();
        }
    }

    public function tagIndex()
    {
        ResponseService::noAnyPermissionThenRedirect([
            'course-tags-list',
            'course-tags-create',
            'course-tags-edit',
            'course-tags-delete',
        ]);

        return view('courses.tags.index', ['type_menu' => 'course-tags']);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function tagStore(Request $request)
    {
        ResponseService::noPermissionThenRedirect(['course-tags-create']);
        $validator = Validator::make($request->all(), [
            'tag' => 'required|string|min:2|max:255|unique:tags,tag,null,id,deleted_at,NULL',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $tagExists = Tag::where('tag', $request->tag)->withTrashed()->first();
            if ($tagExists) {
                if ($tagExists->deleted_at) {
                    $tagExists->restore();
                }
            } else {
                $data = $validator->validated();
                $data['slug'] = HelperService::generateUniqueSlug(Tag::class, $request->tag);
                Tag::create($data);
            }
            DB::commit();
            ResponseService::successResponse('Tag Created Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Tags Controller -> Store Method');
            ResponseService::errorResponse();
        }
    }

    /**
     * Display the specified resource.
     */
    public function tagList()
    {
        ResponseService::noAnyPermissionThenRedirect(['course-tags-list']);
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $showDeleted = request('show_deleted');

        $sql = Tag::query()
            ->when(!empty($search), static function ($query) use ($search): void {
                $query->where(static function ($q) use ($search): void {
                    $q->where('tag', 'LIKE', "%$search%");
                });
            })
            ->when($showDeleted == '1', static function ($query): void {
                $query->onlyTrashed();
            });

        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        foreach ($res as $row) {
            $operate = '';
            if ($showDeleted) {
                if (auth()->user()->can('course-tags-edit')) {
                    $operate .= BootstrapTableService::restoreButton(route('tags.restore', $row->id));
                }
                if (auth()->user()->can('course-tags-delete')) {
                    $operate .= BootstrapTableService::trashButton(route('tags.trash', $row->id));
                }
            } else {
                if (auth()->user()->can('course-tags-edit')) {
                    $operate .= BootstrapTableService::editButton(
                        route('tags.edit', $row->id),
                        true,
                        '#tagEditModal',
                        $row->id,
                    );
                }
                if (auth()->user()->can('course-tags-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('tags.destroy', $row->id));
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

            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;

        return response()->json($bulkData);
    }

    /**
     * Update the specified resource in storage.
     */
    public function tagEdit(Request $request, $id)
    {
        ResponseService::noPermissionThenRedirect('course-tags-edit');
        $validator = Validator::make($request->all(), [
            'tag' => 'required|string|min:2|max:255|unique:tags,tag,' . $id . ',id,deleted_at,NULL',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $tag = Tag::findOrFail($id);
            $data = $validator->validated();
            $data['slug'] = HelperService::generateUniqueSlug(Tag::class, $request->tag, $id);
            $tag->update($data);
            DB::commit();
            ResponseService::successResponse('Tag Updated Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Tags Controller -> Update Method');
            ResponseService::errorResponse();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function tagDestroy($id)
    {
        ResponseService::noPermissionThenRedirect(['course-tags-delete']);
        try {
            DB::beginTransaction();
            Tag::findOrFail($id)->delete();
            DB::commit();
            ResponseService::successResponse('Tag Deleted Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Tags Controller -> Destroy Method');
            ResponseService::errorResponse();
        }
    }

    public function tagRestore($id)
    {
        ResponseService::noPermissionThenRedirect(['course-tags-edit']);
        try {
            DB::beginTransaction();
            Tag::withTrashed()->findOrFail($id)->restore();
            DB::commit();
            ResponseService::successResponse('Tag Restored Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Tags Controller -> Restore Method');
            ResponseService::errorResponse();
        }
    }

    public function tagTrash($id)
    {
        ResponseService::noPermissionThenRedirect(['course-tags-delete']);
        try {
            DB::beginTransaction();
            Tag::withTrashed()->findOrFail($id)->forceDelete();
            DB::commit();
            ResponseService::successResponse('Tag Permanently Deleted Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'Tags Controller -> Trash Method');
            ResponseService::errorResponse();
        }
    }
}

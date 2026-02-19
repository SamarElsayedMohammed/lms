<?php

namespace App\Http\Controllers;

use App\Models\Course\Course;
use App\Models\Instructor;
use App\Models\Slider;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SliderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        ResponseService::noPermissionThenRedirect('sliders-list');
        $sliders = Slider::orderBy('order')->get();

        // Fetch only active and approved courses
        $courses = Course::select('id', 'title')
            ->where('is_active', 1)
            ->where('approval_status', 'approved')
            ->get();

        // Fetch only approved instructors with active users
        $instructors = Instructor::with('user:id,name')
            ->where('status', 'approved')
            ->whereHas('user', static function ($query): void {
                $query->where('is_active', 1);
            })
            ->get();

        // Debug: Log the data to see what's being passed
        Log::info('Sliders data:', $sliders->toArray());
        Log::info('Courses data:', $courses->toArray());
        Log::info('Instructors data:', $instructors->toArray());

        // Convert to simple arrays to avoid serialization issues
        $coursesArray = $courses->map(static fn($course) => [
            'id' => $course->id,
            'title' => $course->title,
        ])->toArray();

        $instructorsArray = $instructors->map(static fn($instructor) => [
            'id' => $instructor->id,
            'user' => $instructor->user
                ? [
                    'id' => $instructor->user->id,
                    'name' => $instructor->user->name,
                ] : null,
        ])->toArray();

        return view('sliders.index', [
            'type_menu' => 'sliders',
            'formFields' => $sliders,
            'courses' => $coursesArray,
            'instructors' => $instructorsArray,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('sliders-create');

        // Base validation rules
        $rules = [
            'image' => 'required|image|mimes:jpg,jpeg,png,webp,svg|max:2048',
            'model_type' => 'required|string|in:default,custom_link,course,instructor',
        ];

        // Conditional validation based on model_type
        $modelType = $request->model_type;

        if ($modelType === 'course') {
            $rules['model_id'] = 'required|exists:courses,id';
            $rules['third_party_link'] = 'nullable';
        } elseif ($modelType === 'instructor') {
            $rules['model_id'] = 'required|exists:instructors,id';
            $rules['third_party_link'] = 'nullable';
        } elseif ($modelType === 'custom_link') {
            $rules['third_party_link'] = 'required|url';
            $rules['model_id'] = 'nullable';
        } elseif ($modelType === 'default') {
            $rules['model_id'] = 'nullable';
            $rules['third_party_link'] = 'nullable';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }

        try {
            // Prepare data for creation
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('sliders', 'public');
            } else {
                return response()->json(['error' => 'Image upload failed.'], 400);
            }

            // Get maximum order value and add 1 for new slider
            $maxOrder = Slider::max('order');
            $newOrder = $maxOrder !== null ? (int) $maxOrder + 1 : 1;

            $slider = new Slider();
            $slider->image = $imagePath;
            $slider->order = $newOrder;

            if ($modelType === 'course') {
                $slider->model_type = \App\Models\Course\Course::class;
                $slider->model_id = $request->model_id;
                $slider->third_party_link = null;
            } elseif ($modelType === 'instructor') {
                $slider->model_type = \App\Models\Instructor::class;
                $slider->model_id = $request->model_id;
                $slider->third_party_link = null;
            } elseif ($modelType === 'custom_link') {
                $slider->model_type = null;
                $slider->model_id = null;
                $slider->third_party_link = $request->third_party_link;
            } elseif ($modelType === 'default') {
                $slider->model_type = null;
                $slider->model_id = null;
                $slider->third_party_link = null;
            }

            $slider->save();
            ResponseService::successResponse('Slider Stored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorRedirect($e, 'SliderController -> Store method');
            ResponseService::errorResponse('An error occurred while storing The data.');
        }
    }

    /**
     * Display the specified resource for Bootstrap Table.
     */
    public function show(Request $request)
    {
        ResponseService::noPermissionThenSendJson('sliders-list');
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'order');
        $order = $request->input('order', 'ASC');
        $search = $request->input('search');
        $showDeleted = $request->input('show_deleted');

        // Use morphWith (Laravel 8.41+)
        $query = Slider::with([
            'model' => static function ($morphTo): void {
                $morphTo->morphWith([
                    \App\Models\Instructor::class => ['user'],
                    \App\Models\Course\Course::class => [],
                ]);
            },
        ])->where(static function ($query) use ($search): void {
            if ($search) {
                $query->whereHasMorph(
                    'model',
                    [
                        \App\Models\Course\Course::class,
                        \App\Models\Instructor::class,
                    ],
                    static function ($q) use ($search): void {
                        $q->where('title', 'LIKE', "%$search%")->orWhere('name', 'LIKE', "%$search%");
                    },
                );
            }
        });

        // Handle show_deleted parameter
        if (!empty($showDeleted)) {
            $query->onlyTrashed();
        }

        $total = $query->count();

        $res = $query->orderBy($sort, $order)->skip($offset)->take($limit)->get();

        $rows = [];
        $no = $offset + 1;

        foreach ($res as $row) {
            $operate = '';
            if (auth()->user()->can('sliders-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('sliders.destroy', $row->id));
            }

            $tempRow = [
                'id' => $row->id,
                'no' => $no++,
                'image' => $row->image_url,
                'order' => $row->order,
                'operate' => $operate,
            ];

            if ($row->model_type === \App\Models\Course\Course::class) {
                $tempRow['type'] = 'Course';
                $tempRow['value'] = optional($row->model)->title ?? '';
            } elseif ($row->model_type === \App\Models\Instructor::class) {
                $tempRow['type'] = 'Instructor';
                $model = $row->model;
                if ($model && $model->user) {
                    $tempRow['value'] = $model->user->name ?? '';
                } else {
                    $tempRow['value'] = '';
                }
            } elseif ($row->third_party_link) {
                $tempRow['type'] = 'Custom Link';
                $tempRow['value'] = $row->third_party_link ?? '';
            } else {
                $tempRow['type'] = 'Default';
                $tempRow['value'] = 'No redirect';
            }

            $rows[] = $tempRow;
        }

        return response()->json([
            'total' => $total,
            'rows' => $rows,
        ]);
    }

    /**
     * Remove the specified resource from storage (soft delete).
     */
    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('sliders-delete');
        try {
            $slider = Slider::findOrFail($id);
            $slider->delete();
            ResponseService::successResponse('Slider Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorRedirect($e, 'SliderController -> Destroy method');
            ResponseService::errorResponse('An error occurred while deleting the slider.');
        }
    }

    /**
     * Update the sort_order of form fields.
     */
    public function updateRankOfFields(Request $request)
    {
        ResponseService::noPermissionThenSendJson('sliders-edit');
        try {
            $validator = Validator::make($request->all(), [
                'ids' => 'required|array',
            ]);
            if ($validator->fails()) {
                ResponseService::errorResponse($validator->errors()->first());
            }
            foreach ($request->ids as $index => $id) {
                Slider::where('id', $id)->update([
                    'order' => $index + 1,
                ]);
            }
            ResponseService::successResponse('Slider Updated Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorRedirect($e, 'SliderController -> Update sort_order method');
            ResponseService::errorResponse();
        }
    }
}

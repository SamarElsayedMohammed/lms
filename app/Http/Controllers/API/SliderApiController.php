<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Slider;
use App\Services\ApiResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SliderApiController extends Controller
{
    /**
     * Get Sliders
     */
    public function getSliders(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => 'nullable|exists:sliders,id',
                'model_type' => 'nullable|string',
                'search' => 'nullable|string|max:255',
                'sort_by' => 'nullable|in:id,order,created_at,updated_at',
                'sort_order' => 'nullable|in:asc,desc',
            ]);

            if ($validator->fails()) {
                ApiResponseService::validationError($validator->errors()->first());
            }

            $query = Slider::with([
                'model' => static function ($morphTo): void {
                    $morphTo->morphWith([
                        \App\Models\Instructor::class => ['user'],
                        \App\Models\Course\Course::class => [],
                    ]);
                },
            ]);

            // Filter by ID
            if ($request->filled('id')) {
                $query->where('id', $request->id);
            }

            // Filter by model type
            if ($request->filled('model_type')) {
                $modelType = $request->model_type;
                if ($modelType === 'course') {
                    $query->where('model_type', \App\Models\Course\Course::class);
                } elseif ($modelType === 'instructor') {
                    $query->where('model_type', \App\Models\Instructor::class);
                } elseif ($modelType === 'custom_link') {
                    $query->whereNull('model_type')->whereNotNull('third_party_link');
                } elseif ($modelType === 'default') {
                    $query->whereNull('model_type')->whereNull('third_party_link');
                }
            }

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(static function ($q) use ($search): void {
                    $q->whereHasMorph(
                        'model',
                        [
                            \App\Models\Course\Course::class,
                            \App\Models\Instructor::class,
                        ],
                        static function ($modelQuery) use ($search): void {
                            if ($modelQuery->getModel() instanceof \App\Models\Course\Course) {
                                $modelQuery->where('title', 'LIKE', "%{$search}%");
                            } elseif ($modelQuery->getModel() instanceof \App\Models\Instructor) {
                                $modelQuery->whereHas('user', static function ($userQuery) use ($search): void {
                                    $userQuery->where('name', 'LIKE', "%{$search}%");
                                });
                            }
                        },
                    )->orWhere('third_party_link', 'LIKE', "%{$search}%");
                });
            }

            // Sorting
            $sortField = $request->sort_by ?? 'order';
            $sortOrder = $request->sort_order ?? 'asc';
            $query->orderBy($sortField, $sortOrder);

            // Get all sliders without pagination
            $sliders = $query->get();

            if ($sliders->isEmpty()) {
                return ApiResponseService::successResponse('No sliders found in the database', []);
            }

            // Transform data for response
            $sliderData = [];
            foreach ($sliders as $slider) {
                $sliderItem = [
                    'id' => $slider->id,
                    'image' => $slider->image_url,
                    'order' => $slider->order,
                    'third_party_link' => $slider->third_party_link,
                    'model_type' => $slider->model_type,
                    'model_id' => $slider->model_id,
                    'created_at' => $slider->created_at,
                    'updated_at' => $slider->updated_at,
                ];

                // Add type, value, and slug based on model
                if ($slider->model_type === \App\Models\Course\Course::class) {
                    $sliderItem['type'] = 'course';
                    $sliderItem['value'] = $slider->model && isset($slider->model->title) ? $slider->model->title : '';
                    $sliderItem['slug'] = $slider->model && isset($slider->model->slug) ? $slider->model->slug : '';
                } elseif ($slider->model_type === \App\Models\Instructor::class) {
                    $sliderItem['type'] = 'instructor';
                    $sliderItem['value'] = optional(optional($slider->model)->user)->name ?? '';
                    $sliderItem['slug'] = optional(optional($slider->model)->user)->slug ?? '';
                } elseif ($slider->third_party_link) {
                    $sliderItem['type'] = 'custom_link';
                    $sliderItem['value'] = $slider->third_party_link ?? '';
                    $sliderItem['slug'] = null;
                } else {
                    $sliderItem['type'] = 'default';
                    $sliderItem['value'] = 'No redirect';
                    $sliderItem['slug'] = null;
                }

                $sliderData[] = $sliderItem;
            }

            return ApiResponseService::successResponse('Sliders fetched successfully', $sliderData);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get sliders');
            return ApiResponseService::errorResponse('Failed to get sliders ' . $e->getMessage());
        }
    }
}

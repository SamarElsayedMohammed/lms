<?php

namespace App\Http\Controllers\API\Admin;

use App\Models\Course\Course;
use App\Models\Course\CourseCountryPrice;
use App\Models\SupportedCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CourseCountryPricesAdminController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * List all specific country prices for a course.
     */
    public function index(int $courseId): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $course = Course::find($courseId);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        $prices = CourseCountryPrice::where('course_id', $courseId)
            ->with('currency')
            ->get();

        return $this->jsonSuccess(__('Country prices retrieved'), [
            'base_price_egp' => (float) $course->price,
            'base_discount_price_egp' => (float) $course->discount_price,
            'country_prices' => $prices
        ]);
    }

    /**
     * Upsert a single country price.
     */
    public function store(Request $request, int $courseId): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $validator = Validator::make($request->all(), [
            'country_code' => 'required|char:2',
            'price_egp' => 'required|numeric|min:0',
            'discount_price_egp' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $course = Course::find($courseId);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        $countryPrice = CourseCountryPrice::updateOrCreate(
            ['course_id' => $courseId, 'country_code' => $request->input('country_code')],
            [
                'price_egp' => $request->input('price_egp'),
                'discount_price_egp' => $request->input('discount_price_egp'),
                'is_active' => $request->boolean('is_active', true)
            ]
        );

        return $this->jsonSuccess(__('Country price saved'), $countryPrice->load('currency'));
    }

    /**
     * Bulk update/insert country prices.
     */
    public function bulk(Request $request, int $courseId): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $validator = Validator::make($request->all(), [
            'prices' => 'required|array',
            'prices.*.country_code' => 'required|char:2',
            'prices.*.price_egp' => 'required|numeric|min:0',
            'prices.*.discount_price_egp' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $course = Course::find($courseId);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        foreach ($request->input('prices') as $priceData) {
            CourseCountryPrice::updateOrCreate(
                ['course_id' => $courseId, 'country_code' => $priceData['country_code']],
                [
                    'price_egp' => $priceData['price_egp'],
                    'discount_price_egp' => $priceData['discount_price_egp'] ?? null,
                    'is_active' => true
                ]
            );
        }

        return $this->jsonSuccess(__('Bulk prices updated'), $course->load('countryPrices.currency'));
    }

    /**
     * Remove a country-specific price.
     */
    public function destroy(int $courseId, string $countryCode): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $deleted = CourseCountryPrice::where('course_id', $courseId)
            ->where('country_code', $countryCode)
            ->delete();

        if (!$deleted) {
            return $this->jsonError(__('Price not found or already deleted'), 404);
        }

        return $this->jsonSuccess(__('Country price removed'));
    }
}

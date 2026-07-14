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

        $data = $request->all();
        if (isset($data['price']) && !isset($data['price_local'])) {
            $data['price_local'] = $data['price'];
        }
        if (isset($data['country_code'])) {
            $data['country_code'] = strtoupper((string) $data['country_code']);
        }

        $validator = Validator::make($data, [
            'country_code' => 'required|string|size:2',
            'price_local' => 'required|numeric|min:0',
            'discount_price_local' => 'nullable|numeric|min:0',
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
            ['course_id' => $courseId, 'country_code' => $data['country_code']],
            [
                'price_local' => $data['price_local'],
                'discount_price_local' => $data['discount_price_local'] ?? null,
                'is_active' => $request->boolean('is_active', true)
            ]
        );

        \App\Models\SupportedCurrency::ensureCurrencyExists($data['country_code']);

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
            'prices.*.country_code' => 'required|string|size:2',
            'prices.*.price_local' => 'required|numeric|min:0',
            'prices.*.discount_price_local' => 'nullable|numeric|min:0',
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
                    'price_local' => $priceData['price_local'],
                    'discount_price_local' => $priceData['discount_price_local'] ?? null,
                    'is_active' => true
                ]
            );
            \App\Models\SupportedCurrency::ensureCurrencyExists($priceData['country_code']);
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

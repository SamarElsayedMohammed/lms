<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Billing Details API Controller
 *
 * Manages user billing details for the authenticated user.
 * Each user can have only ONE billing detail record.
 *
 */
final class BillingDetailsApiController extends Controller
{
    use HasApiResponse;

    /**
     * Get authenticated user's billing details
     *
     * Retrieves the billing details for the currently authenticated user.
     * Returns null if no billing details exist.
     *
     */
    public function show()
    {
        try {
            $user = Auth::user();
            $billingDetails = $user?->billingDetails;

            if (!$billingDetails) {
                return $this->ok(message: 'No Billing details found');
            }

            return $this->ok($billingDetails->formatForApi(), 'Billing details retrieved successfully');
        } catch (\Exception $e) {
            return $this->serverError('Failed to retrieve billing details', exception: $e);
        }
    }

    /**
     * Create billing details for authenticated user
     *
     * Creates a new billing details record for the authenticated user.
     * Only one billing detail record is allowed per user (enforced by database constraint).
     *
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'country_code' => 'required|string|size:2',
            'address' => 'required|string|max:500',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'tax_id' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->unprocessableEntity($validator->errors()->first());
        }

        try {
            $user = Auth::user();

            if ($user->billingDetails) {
                return $this->conflict('Billing details already exist. Use PATCH to update existing details.');
            }

            $billingDetails = $user->billingDetails()->create($request->only([
                'first_name',
                'last_name',
                'country_code',
                'address',
                'city',
                'state',
                'postal_code',
                'tax_id',
            ]));

            return $this->ok($billingDetails->formatForApi(), 'Billing details saved successfully');
        } catch (\Exception $e) {
            return $this->serverError('Failed to save billing details', exception: $e);
        }
    }

    /**
     * Update authenticated user's billing details
     *
     * Updates existing billing details for the authenticated user.
     * All fields are optional - only provided fields will be updated.
     * At least one field must be provided.
     *
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'country_code' => 'nullable|string|size:2',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'tax_id' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->unprocessableEntity($validator->errors()->first());
        }

        $data = $request->only([
            'first_name',
            'last_name',
            'country_code',
            'address',
            'city',
            'state',
            'postal_code',
            'tax_id',
        ]);

        if (empty($data)) {
            return $this->unprocessableEntity('At least one field is required');
        }

        try {
            $user = Auth::user();
            $billingDetails = $user?->billingDetails;

            if (!$billingDetails) {
                return $this->notFound('Billing details not found');
            }

            $billingDetails->update($data);

            return $this->ok($billingDetails->fresh()->formatForApi(), 'Billing details updated successfully');
        } catch (\Exception $e) {
            return $this->serverError('Failed to update billing details', exception: $e);
        }
    }
}

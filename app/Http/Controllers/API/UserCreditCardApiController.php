<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\UserCreditCard;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;

class UserCreditCardApiController extends Controller
{
    public function index()
    {
        try {
            $user = Auth::user();
            $cards = $user->creditCards()->get();

            return ApiResponseService::successResponse('Credit cards retrieved successfully', $cards);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse('Failed to retrieve credit cards', null, 500, $th);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'card_holder_name' => 'nullable|string|max:255',
                'last_four_digits' => ['required', 'string', 'regex:/^\d{4}$/'],
                'brand' => 'nullable|string|max:50',
                'exp_month' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])$/'],
                'exp_year' => ['required', 'string', 'regex:/^\d{4}$/'],
                'gateway_token' => 'required|string|max:2048',
                'is_default' => 'boolean',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $expiryBoundary = now()->startOfMonth();
            $expiry = now()->setDate((int) $request->exp_year, (int) $request->exp_month, 1)->startOfMonth();
            if ($expiry->lt($expiryBoundary)) {
                return ApiResponseService::validationError('Credit card expiry date is in the past.');
            }

            $user = Auth::user();
            $existingCard = $user->creditCards()
                ->where(function ($query) use ($request) {
                    $query->where('gateway_token', $request->gateway_token)
                        ->orWhere(function ($query) use ($request) {
                            $query->where('last_four_digits', $request->last_four_digits)
                                ->where('exp_month', $request->exp_month)
                                ->where('exp_year', $request->exp_year)
                                ->when($request->filled('brand'), fn ($query) => $query->where('brand', $request->brand));
                        });
                })
                ->first();

            if ($existingCard) {
                return ApiResponseService::errorResponse('Credit card already exists', $existingCard, 409);
            }

            $isDefault = $request->boolean('is_default');

            // If this is the user's first card, make it default regardless
            if ($user->creditCards()->count() === 0) {
                $isDefault = true;
            }

            if ($isDefault) {
                // Remove default from other cards
                $user->creditCards()->update(['is_default' => false]);
            }

            $card = $user->creditCards()->create([
                'card_holder_name' => $request->card_holder_name,
                'last_four_digits' => $request->last_four_digits,
                'brand' => $request->brand,
                'exp_month' => $request->exp_month,
                'exp_year' => $request->exp_year,
                'gateway_token' => $request->gateway_token,
                'is_default' => $isDefault,
            ]);

            return ApiResponseService::successResponse('Credit card added successfully', $card);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse('Failed to add credit card', null, 500, $th);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'card_holder_name' => 'nullable|string|max:255',
                'exp_month' => ['nullable', 'string', 'regex:/^(0[1-9]|1[0-2])$/'],
                'exp_year' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            $card = $user->creditCards()->find($id);

            if (!$card) {
                return ApiResponseService::validationError('Credit card not found');
            }


            $nextMonth = (string) ($request->input('exp_month') ?? $card->exp_month);
            $nextYear = (string) ($request->input('exp_year') ?? $card->exp_year);
            $expiryBoundary = now()->startOfMonth();
            $expiry = now()->setDate((int) $nextYear, (int) $nextMonth, 1)->startOfMonth();
            if ($expiry->lt($expiryBoundary)) {
                return ApiResponseService::validationError('Credit card expiry date is in the past.');
            }

            $card->update($request->only(['card_holder_name', 'exp_month', 'exp_year']));

            return ApiResponseService::successResponse('Credit card updated successfully', $card);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse('Failed to update credit card', null, 500, $th);
        }
    }

    public function destroy($id)
    {
        try {
            $user = Auth::user();
            $card = $user->creditCards()->find($id);

            if (!$card) {
                return ApiResponseService::validationError('Credit card not found');
            }

            $wasDefault = $card->is_default;
            $card->delete();

            // If we deleted the default card, make the most recently added card default if any exists
            if ($wasDefault) {
                $latestCard = $user->creditCards()->latest()->first();
                if ($latestCard) {
                    $latestCard->update(['is_default' => true]);
                }
            }

            return ApiResponseService::successResponse('Credit card deleted successfully');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse('Failed to delete credit card', null, 500, $th);
        }
    }

    public function setDefault($id)
    {
        try {
            $user = Auth::user();
            $card = $user->creditCards()->find($id);

            if (!$card) {
                return ApiResponseService::validationError('Credit card not found');
            }

            // Remove default from other cards
            $user->creditCards()->update(['is_default' => false]);

            $card->update(['is_default' => true]);

            return ApiResponseService::successResponse('Credit card set as default successfully', $card);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse('Failed to set default credit card', null, 500, $th);
        }
    }
}

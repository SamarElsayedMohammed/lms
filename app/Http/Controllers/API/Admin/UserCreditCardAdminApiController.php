<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserCreditCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserCreditCardAdminApiController extends Controller
{
    /**
     * Get credit cards for a specific user
     */
    public function indexByUserId(int $userId): JsonResponse
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['error' => true, 'message' => 'User not found'], 404);
        }

        $cards = $user->creditCards()->get();

        return response()->json([
            'error' => false,
            'message' => 'Credit cards retrieved successfully',
            'data' => $cards
        ]);
    }

    /**
     * Store a new credit card for a specific user
     */
    public function storeForUser(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['error' => true, 'message' => 'User not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'card_holder_name' => 'nullable|string|max:255',
            'last_four_digits' => 'required|string|size:4',
            'brand' => 'nullable|string|max:50',
            'exp_month' => 'required|string|size:2',
            'exp_year' => 'required|string|size:4',
            'gateway_token' => 'required|string',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => true, 'message' => $validator->errors()->first()], 422);
        }
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
            return response()->json([
                'error' => true,
                'message' => 'Credit card already exists for this user',
                'data' => $existingCard
            ], 409);
        }


        $isDefault = $request->boolean('is_default');

        if ($user->creditCards()->count() === 0) {
            $isDefault = true;
        }

        if ($isDefault) {
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

        return response()->json([
            'error' => false,
            'message' => 'Credit card added successfully for user',
            'data' => $card
        ]);
    }

    /**
     * Update an existing credit card
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'card_holder_name' => 'nullable|string|max:255',
            'exp_month' => 'nullable|string|size:2',
            'exp_year' => 'nullable|string|size:4',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => true, 'message' => $validator->errors()->first()], 422);
        }

        $card = UserCreditCard::find($id);

        if (!$card) {
            return response()->json(['error' => true, 'message' => 'Credit card not found'], 404);
        }

        $card->update($request->only(['card_holder_name', 'exp_month', 'exp_year']));

        return response()->json([
            'error' => false,
            'message' => 'Credit card updated successfully',
            'data' => $card
        ]);
    }

    /**
     * Delete a credit card
     */
    public function destroy(int $id): JsonResponse
    {
        $card = UserCreditCard::find($id);

        if (!$card) {
            return response()->json(['error' => true, 'message' => 'Credit card not found'], 404);
        }

        $user = $card->user;
        $wasDefault = $card->is_default;
        $card->delete();

        if ($wasDefault && $user) {
            $latestCard = $user->creditCards()->latest()->first();
            if ($latestCard) {
                $latestCard->update(['is_default' => true]);
            }
        }

        return response()->json([
            'error' => false,
            'message' => 'Credit card deleted successfully'
        ]);
    }

    /**
     * Set a credit card as default
     */
    public function setDefault(int $id): JsonResponse
    {
        $card = UserCreditCard::find($id);

        if (!$card) {
            return response()->json(['error' => true, 'message' => 'Credit card not found'], 404);
        }

        $user = $card->user;
        if ($user) {
            $user->creditCards()->update(['is_default' => false]);
        }

        $card->update(['is_default' => true]);

        return response()->json([
            'error' => false,
            'message' => 'Credit card set as default successfully',
            'data' => $card
        ]);
    }
}

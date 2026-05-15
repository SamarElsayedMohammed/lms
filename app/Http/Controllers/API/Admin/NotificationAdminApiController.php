<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SubscriptionPlan;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ManualCustomNotification; // I will create this next
use Illuminate\Support\Facades\Validator;

class NotificationAdminApiController extends Controller
{
    /**
     * Send bulk notification to users based on filters
     */
    public function sendBulkNotification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'target_type' => 'required|in:all,by_plan,no_plan',
            'plan_id' => 'required_if:target_type,by_plan|exists:subscription_plans,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'title_ar' => 'nullable|string|max:255',
            'message_ar' => 'nullable|string',
            'action_url' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::errorResponse('Validation failed', $validator->errors(), 422);
        }

        $query = User::query()->where('role', '!=', 'admin');

        if ($request->target_type === 'by_plan') {
            $query->whereHas('subscriptions', function($q) use ($request) {
                $q->where('subscription_plan_id', $request->plan_id)
                  ->where('status', 'active');
            });
        } elseif ($request->target_type === 'no_plan') {
            $query->whereDoesntHave('subscriptions', function($q) {
                $q->where('status', 'active');
            });
        }

        $users = $query->get();
        $count = $users->count();

        if ($count === 0) {
            return ApiResponseService::errorResponse('No users found for the selected criteria');
        }

        // Send notifications
        // Note: Using a queue is recommended for large numbers of users
        foreach ($users as $user) {
            $user->notify(new ManualCustomNotification([
                'title' => $request->title,
                'message' => $request->message,
                'title_ar' => $request->title_ar ?? $request->title,
                'message_ar' => $request->message_ar ?? $request->message,
                'action_url' => $request->action_url ?? '#',
                'type' => 'admin_manual'
            ]));
        }

        return ApiResponseService::successResponse("Notification sent successfully to {$count} users.");
    }
}

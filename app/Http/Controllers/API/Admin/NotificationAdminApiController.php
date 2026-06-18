<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\NotificationCampaign;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use App\Notifications\ManualCustomNotification;
use Illuminate\Support\Facades\Validator;

class NotificationAdminApiController extends Controller
{
    /**
     * Get list of sent notification campaigns
     */
    public function index()
    {
        $campaigns = NotificationCampaign::with('plan:id,name')->orderBy('created_at', 'desc')->get();
        return ApiResponseService::successResponse('Notifications retrieved successfully', $campaigns);
    }

    /**
     * Send bulk notification to users based on filters
     */
    public function sendBulkNotification(Request $request)
    {
        $rules = [
            'target_type' => 'required|in:all,free_users,any_plan,by_plan,students,instructors',
            'plan_id'     => 'required_if:target_type,by_plan|exists:subscription_plans,id',
            'title'       => 'required|string|max:255',
            'message'     => 'required|string',
            'title_ar'    => 'nullable|string|max:255',
            'message_ar'  => 'nullable|string',
            'action_url'  => 'nullable|string',
        ];

        if ($request->hasFile('image')) {
            $rules['image'] = 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048';
        } else {
            $rules['image'] = 'nullable|string|max:2048';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return ApiResponseService::errorResponse('Validation failed', $validator->errors(), 422);
        }

        // Exclude admins
        $query = User::query()->whereDoesntHave('roles', function ($q) {
            $q->whereIn('name', ['Super Admin', 'Admin', 'Supervisor', 'Staff']);
        });

        switch ($request->target_type) {
            case 'free_users':
                $query->whereDoesntHave('subscriptions', function($q) {
                    $q->where('status', 'active');
                });
                break;
            case 'any_plan':
                $query->whereHas('subscriptions', function($q) {
                    $q->where('status', 'active');
                });
                break;
            case 'by_plan':
                $query->whereHas('subscriptions', function($q) use ($request) {
                    $q->where('subscription_plan_id', $request->plan_id)
                      ->where('status', 'active');
                });
                break;
            case 'students':
                // Assuming students are those without instructor/admin roles
                $query->whereDoesntHave('roles', function($q) {
                    $q->whereIn('name', ['Instructor']);
                });
                break;
            case 'instructors':
                $query->whereHas('roles', function($q) {
                    $q->where('name', 'Instructor');
                });
                break;
        }

        $users = $query->get();
        $count = $users->count();

        if ($count === 0) {
            return ApiResponseService::errorResponse('No users found for the selected criteria');
        }

        // Handle image: upload if file, otherwise use string URL
        $imageUrl = null;
        if ($request->hasFile('image')) {
            $path = \App\Services\FileService::upload($request->file('image'), 'notifications');
            $imageUrl = \App\Services\FileService::getFileUrl($path);
        } else {
            $imageUrl = $request->input('image');
        }

        // Save campaign record
        $campaign = NotificationCampaign::create([
            'title'       => $request->title,
            'message'     => $request->message,
            'target_type' => $request->target_type,
            'plan_id'     => $request->target_type === 'by_plan' ? $request->plan_id : null,
            'sent_count'  => $count,
            'image'       => $imageUrl,
        ]);

        // Send notifications
        foreach ($users as $user) {
            $user->notify(new ManualCustomNotification([
                'title'      => $request->title,
                'message'    => $request->message,
                'title_ar'   => $request->title_ar ?? $request->title,
                'message_ar' => $request->message_ar ?? $request->message,
                'action_url' => $request->action_url ?? '#',
                'image'      => $imageUrl,
                'type'       => 'admin_manual'
            ]));
        }

        return ApiResponseService::successResponse("Notification sent successfully to {$count} users.", $campaign);
    }

    /**
     * Delete a notification campaign from the history
     */
    public function destroy($id)
    {
        $campaign = NotificationCampaign::find($id);
        
        if (!$campaign) {
            return ApiResponseService::errorResponse('Notification not found', null, 404);
        }

        $campaign->delete();

        return ApiResponseService::successResponse('Notification deleted successfully');
    }
}

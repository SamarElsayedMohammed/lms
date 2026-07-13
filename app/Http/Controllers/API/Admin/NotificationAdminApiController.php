<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationCampaign;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\ManualCustomNotification;
use App\Services\ApiResponseService;
use App\Services\FileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationAdminApiController extends Controller
{
    /**
     * Get list of sent notification campaigns (paginated)
     */
    public function index(Request $request)
    {
        $campaigns = NotificationCampaign::with('plan:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return ApiResponseService::successResponse('Notifications retrieved successfully', $campaigns);
    }

    /**
     * Send bulk notification to users based on target type, optional plan filter, and delivery channels.
     *
     * target_type values:
     *   all                  — every non-admin user
     *   free_users           — users with no subscription
     *   expired_subscriptions— users with only expired subscriptions
     *   any_plan             — users with any active subscription
     *   by_plan              — users subscribed to ONE specific plan (plan_id)
     *   by_plans             — users subscribed to ANY of the given plans (plan_ids[])
     *   students             — users without instructor role
     *   instructors          — users with instructor role
     *
     * channels (optional, defaults to global config):
     *   web   — in-app (database + FCM push)
     *   mail  — email
     */
    public function sendBulkNotification(Request $request)
    {
        $rules = [
            'target_type' => 'required|in:all,free_users,expired_subscriptions,any_plan,by_plan,by_plans,students,instructors',
            'plan_id'     => 'required_if:target_type,by_plan|nullable|exists:subscription_plans,id',
            'plan_ids'    => 'required_if:target_type,by_plans|nullable|array|min:1',
            'plan_ids.*'  => 'integer|exists:subscription_plans,id',
            'title'       => 'required|string|max:255',
            'message'     => 'required|string',
            'title_ar'    => 'nullable|string|max:255',
            'message_ar'  => 'nullable|string',
            'action_url'  => 'nullable|string',
            'icon'        => 'nullable|string|max:64',
            'icon_color'  => 'nullable|string|max:16',
            'channels'    => 'nullable|array',
            'channels.*'  => 'string|in:web,mail',
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

        // ── Build user query (always exclude admin roles) ────────────────────
        $query = User::query()->whereDoesntHave('roles', function ($q) {
            $q->whereIn('name', ['Super Admin', 'Admin', 'Supervisor', 'Staff']);
        });

        switch ($request->target_type) {
            case 'free_users':
                $query->whereDoesntHave('subscriptions');
                break;

            case 'expired_subscriptions':
                $query->whereHas('subscriptions', function ($q) {
                    $q->where('status', 'expired');
                })->whereDoesntHave('subscriptions', function ($q) {
                    $q->where('status', 'active');
                });
                break;

            case 'any_plan':
                $query->whereHas('subscriptions', function ($q) {
                    $q->where('status', 'active');
                });
                break;

            case 'by_plan':
                $query->whereHas('subscriptions', function ($q) use ($request) {
                    $q->where('subscription_plan_id', $request->plan_id)
                      ->where('status', 'active');
                });
                break;

            case 'by_plans':
                $planIds = $request->plan_ids;
                $query->whereHas('subscriptions', function ($q) use ($planIds) {
                    $q->whereIn('subscription_plan_id', $planIds)
                      ->where('status', 'active');
                });
                break;

            case 'students':
                $query->whereDoesntHave('roles', function ($q) {
                    $q->whereIn('name', ['Instructor']);
                });
                break;

            case 'instructors':
                $query->whereHas('roles', function ($q) {
                    $q->where('name', 'Instructor');
                });
                break;

            // 'all' — no extra filter
        }

        $count = $query->count();
        if ($count === 0) {
            return ApiResponseService::errorResponse('No users found for the selected criteria');
        }

        // ── Resolve channels ──────────────────────────────────────────────────
        // UI sends 'web' (in-app/push) and/or 'mail'.
        // Internally the notification system uses 'database' for in-app storage.
        $requestedChannels = $request->input('channels', []);
        $internalChannels  = null; // null = fall back to global config

        if (!empty($requestedChannels)) {
            $internalChannels = [];
            if (in_array('web', $requestedChannels, true)) {
                $internalChannels[] = 'database';
            }
            if (in_array('mail', $requestedChannels, true)) {
                $internalChannels[] = 'mail';
            }
            // If somehow empty after mapping, fall back to global config
            if (empty($internalChannels)) {
                $internalChannels = null;
            }
        }

        // ── Handle image ──────────────────────────────────────────────────────
        $imageUrl = null;
        if ($request->hasFile('image')) {
            $path     = FileService::upload($request->file('image'), 'notifications');
            $imageUrl = FileService::getFileUrl($path);
        } else {
            $imageUrl = $request->input('image');
        }

        // ── Save campaign record ──────────────────────────────────────────────
        $campaign = NotificationCampaign::create([
            'title'       => $request->title,
            'message'     => $request->message,
            'target_type' => $request->target_type,
            'plan_id'     => $request->target_type === 'by_plan'  ? $request->plan_id  : null,
            'plan_ids'    => $request->target_type === 'by_plans' ? $request->plan_ids : null,
            'channels'    => $requestedChannels ?: null,
            'sent_count'  => $count,
            'image'       => $imageUrl,
            'icon'        => $request->input('icon'),
            'icon_color'  => $request->input('icon_color'),
        ]);

        // ── Dispatch notifications in chunks to avoid memory pressure ─────────
        $notificationData = [
            'title'      => $request->title,
            'message'    => $request->message,
            'title_ar'   => $request->title_ar  ?? $request->title,
            'message_ar' => $request->message_ar ?? $request->message,
            'action_url' => $request->action_url ?? '#',
            'image'      => $imageUrl,
            'icon'       => $request->input('icon'),
            'icon_color' => $request->input('icon_color'),
            'type'       => 'admin_manual',
        ];

        $query->chunk(200, function ($users) use ($notificationData, $internalChannels) {
            \Illuminate\Support\Facades\Notification::send(
                $users,
                new ManualCustomNotification($notificationData, $internalChannels)
            );
        });

        return ApiResponseService::successResponse(
            "Notification sent successfully to {$count} users. Processing in background.",
            $campaign->load('plan:id,name')
        );
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

    /**
     * Return an HTML preview of the general-notification email template.
     * Used by the admin UI to show a live preview of what the email looks like.
     */
    public function emailPreview(Request $request)
    {
        $title   = $request->input('title',   'عنوان الإشعار التجريبي');
        $message = $request->input('message', 'هذا مثال على نص الإشعار الذي سيصله المستخدم عبر البريد الإلكتروني.');

        return response()->view('emails.general-notification', [
            'notificationTitle'   => $title,
            'greeting'            => 'مرحباً بك في المنصة،',
            'notificationContent' => $message,
            'actionUrl'           => url('/'),
            'actionText'          => 'زيارة المنصة',
            'imageUrl'            => null,
        ]);
    }
}

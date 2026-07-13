<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\User;

use App\Models\ContactMessage;
use App\Services\NotificationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ContactMessageAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('contact-messages-list');

        $search = $request->input('search');
        $status = $request->input('status');
        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = ContactMessage::query()
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            }))
            ->when($status, fn ($q) => $q->where('status', $status));

        $messages = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $newCount = ContactMessage::new()->count();

        return $this->jsonSuccess(__('Contact messages retrieved'), [
            'messages' => $messages,
            'new_count' => $newCount,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('contact-messages-list');

        $message = ContactMessage::find($id);
        if (!$message) {
            return $this->jsonError(__('Contact message not found'), 404);
        }

        return $this->jsonSuccess(__('Contact message retrieved'), $message);
    }

    public function thread(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('contact-messages-list');

        $message = ContactMessage::find($id);
        if (!$message) {
            return $this->jsonError(__('Contact message not found'), 404);
        }

        // Get replies
        $replies = \App\Models\ContactMessageReply::where('contact_message_id', $message->id)
            ->orderBy('created_at', 'asc')
            ->get();

        $conversation = [];
        
        // Add the initial message as the first item in the conversation
        $conversation[] = [
            'id' => 0,
            'sender' => 'user',
            'message' => $message->message,
            'created_at' => $message->created_at,
        ];

        // If there's a legacy reply_message on the parent, add it
        if ($message->reply_message) {
            $conversation[] = [
                'id' => -1,
                'sender' => 'admin',
                'message' => $message->reply_message,
                'created_at' => $message->updated_at,
            ];
        }

        foreach ($replies as $reply) {
            $conversation[] = [
                'id' => $reply->id,
                'sender' => $reply->sender_type,
                'message' => $reply->message,
                'created_at' => $reply->created_at,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $message->id,
                'subject' => \Illuminate\Support\Str::limit($message->message, 50),
                'status' => $message->status,
                'conversation' => $conversation,
            ]
        ]);
    }

    public function markRead(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('contact-messages-list');

        $message = ContactMessage::find($id);
        if (!$message) {
            return $this->jsonError(__('Contact message not found'), 404);
        }

        $message->markAsRead();
        return $this->jsonSuccess(__('Message marked as read'), $message->fresh());
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('contact-messages-list');

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:new,read,waiting_admin,replied,closed,completed,reopened',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $message = ContactMessage::find($id);
        if (!$message) {
            return $this->jsonError(__('Contact message not found'), 404);
        }

        $message->update(['status' => $request->status]);
        return $this->jsonSuccess(__('Status updated'), $message->fresh());
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('contact-messages-edit');

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:2',
        ]);

        if ($validator->fails()) {
            // Check legacy reply_message as fallback for backward compatibility
            if ($request->has('reply_message')) {
                $validator = Validator::make($request->all(), [
                    'reply_message' => 'required|string|min:2',
                ]);
                if ($validator->fails()) {
                    return $this->jsonError($validator->errors()->first(), 422);
                }
                $replyMessage = $request->reply_message;
            } else {
                return $this->jsonError($validator->errors()->first(), 422);
            }
        } else {
            $replyMessage = $request->message;
        }

        $message = ContactMessage::find($id);
        if (!$message) {
            return $this->jsonError(__('Contact message not found'), 404);
        }

        try {
            $appName      = \App\Services\HelperService::systemSettings('app_name') ?? 'LMS';

            // 1️⃣ Save the reply in contact_message_replies
            \App\Models\ContactMessageReply::create([
                'contact_message_id' => $message->id,
                'admin_id' => auth()->id(),
                'sender_type' => 'admin',
                'message' => $replyMessage,
            ]);

            // 2️⃣ Send email reply
            if (in_array('mail', NotificationSettingsService::getChannelsFor('ContactReplyNotification', ['mail', 'database']), true)) {
                Mail::send(
                'emails.contact-reply',
                [
                    'contactMessage' => $message,
                    'appName'        => $appName,
                    'replyMessage'   => $replyMessage,
                ],
                function ($mail) use ($message, $appName) {
                    $mail->to($message->email)
                        ->subject("Reply to your inquiry - {$appName}");
                }
            );
            }

            // 3️⃣ Send in-app + FCM notification to the user (only if logged-in user sent the message)
            if ($message->user_id && in_array('database', NotificationSettingsService::getChannelsFor('ContactReplyNotification', ['mail', 'database']), true)) {
                // Create support_reply notification
                \App\Models\UserNotification::create([
                    'user_id' => $message->user_id,
                    'type' => 'support_reply',
                    'title' => 'تم الرد على رسالتك',
                    'message' => 'قام فريق الدعم بالرد على استفسارك',
                    'url' => '/contact-us?tab=conversations',
                ]);

                $user = \App\Models\User::find($message->user_id);
                if ($user) {
                    try {
                        $user->notify(new \App\Notifications\ContactReplyNotification(
                            $message,
                            $replyMessage,
                            $appName,
                        ));
                    } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                        throw $e;
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::warning(
                            'ContactMessageAdminApiController: Failed to send in-app notification to user',
                            ['user_id' => $user->id, 'error' => $e->getMessage()],
                        );
                    }
                }
            }

            // 4️⃣ Update status to replied
            $message->update([
                'status' => 'replied',
                // We don't overwrite the old reply_message field as we now use the replies table
            ]);

            return response()->json(['success' => true]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->jsonError(__('Failed to send reply: ') . $e->getMessage(), 500);
        }
    }
}

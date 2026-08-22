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

        $validator = Validator::make($request->all(), [
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|in:new,read,waiting_admin,replied,closed,completed,reopened',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $search = trim((string) $request->input('search', ''));
        $status = $request->input('status');
        $perPage = (int) $request->input('per_page', 15);

        $query = ContactMessage::query()
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            }))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from_date')))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('to_date')));

        $messages = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $newCount = ContactMessage::new()->count();
        $pendingCount = ContactMessage::whereIn('status', ['new', 'read', 'waiting_admin', 'reopened'])->count();
        $completedCount = ContactMessage::whereIn('status', ['closed', 'completed'])->count();
        $totalCount = ContactMessage::count();

        return $this->jsonSuccess(__('Contact messages retrieved'), [
            'messages' => $messages,
            'new_count' => $newCount,
            'status_counts' => [
                'pending' => $pendingCount,
                'completed' => $completedCount,
                'total' => $totalCount,
            ],
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
                'first_name' => $message->first_name,
                'email' => $message->email,
                'subject' => $message->subject ?: \Illuminate\Support\Str::limit($message->message, 50),
                'status' => $message->status,
                'message' => $message->message,
                'metadata' => $message->metadata,
                'created_at' => $message->created_at,
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
            'message' => 'required|string|min:2|max:5000',
        ]);

        if ($validator->fails()) {
            // Check legacy reply_message as fallback for backward compatibility
            if ($request->has('reply_message')) {
                $validator = Validator::make($request->all(), [
                    'reply_message' => 'required|string|min:2|max:5000',
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

        $replyMessage = trim(strip_tags((string) $replyMessage));
        if (mb_strlen($replyMessage) < 2) {
            return $this->jsonError('Reply must contain at least two visible characters.', 422);
        }

        $message = ContactMessage::find($id);
        if (!$message) {
            return $this->jsonError(__('Contact message not found'), 404);
        }

        try {
            $appName      = \App\Services\HelperService::systemSettings('app_name') ?? 'LMS';
            $recipientUser = $message->user_id ? \App\Models\User::find($message->user_id) : null;

            // 1️⃣ Save the reply in contact_message_replies
            \App\Models\ContactMessageReply::create([
                'contact_message_id' => $message->id,
                'admin_id' => auth()->id(),
                'sender_type' => 'admin',
                'message' => $replyMessage,
            ]);

            // 2️⃣ Send email reply
            if (in_array('mail', NotificationSettingsService::getChannelsFor(
                'ContactReplyNotification',
                ['mail'],
                $recipientUser,
            ), true)) {
                try {
                    Mail::queue(
                    'emails.contact-reply',
                    [
                        'contactMessage' => $message,
                        'appName'        => $appName,
                        'replyMessage'   => $replyMessage,
                    ],
                    function ($mail) use ($message, $appName) {
                        $mail->to($message->email)
                            ->subject("رد على استفسارك - {$appName}");
                    }
                    );
                } catch (\Throwable $mailError) {
                    \Illuminate\Support\Facades\Log::error('Contact reply email failed', [
                        'contact_message_id' => $message->id,
                        'error' => $mailError->getMessage(),
                    ]);
                }
            }

            // 3️⃣ Send in-app + FCM notification to the user (only if logged-in user sent the message)
            if ($message->user_id) {
                $user = $recipientUser;
                if ($user) {
                    try {
                        if (in_array('database', NotificationSettingsService::getChannelsFor(
                            'ContactReplyNotification',
                            ['database'],
                            $user,
                        ), true)) {
                            \App\Models\UserNotification::create([
                                'user_id' => $message->user_id,
                                'contact_message_id' => $message->id,
                                'type' => 'support_reply',
                                'title' => 'تم الرد على رسالتك',
                                'message' => 'قام فريق الدعم بالرد على استفسارك',
                                'url' => '/messages?ticket=' . $message->id,
                            ]);
                        }

                        $notification = new \App\Notifications\ContactReplyNotification(
                            $message,
                            $replyMessage,
                            $appName,
                        );
                        $notification->sendPushTo($user);
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
            return $this->jsonError('تعذر إرسال الرد حالياً.', 500);
        }
    }
}

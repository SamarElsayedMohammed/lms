<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'status' => 'required|in:new,read,replied,closed',
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
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContactMessageController extends Controller
{
    /**
     * Display the contact messages list view
     */
    public function index(Request $request)
    {
        ResponseService::noPermissionThenRedirect('contact-messages-list');
        $type_menu = 'contact-messages';

        return view('admin.contact-messages.index', compact('type_menu'));
    }

    /**
     * Get contact messages data for AJAX table
     */
    public function getData(Request $request)
    {
        ResponseService::noPermissionThenSendJson('contact-messages-list');

        $query = ContactMessage::query();

        // Search filter (name/email)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(static function ($q) use ($search): void {
                $q->where('first_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Sorting
        $sortField = $request->get('sort_field', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        // Only allow sorting by created_at for security
        if ($sortField === 'created_at' && in_array($sortOrder, ['asc', 'desc'], true)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $messages = $query->get();

        $data = $messages->map(static function (ContactMessage $message) {
            $operate = '';

            // View button - requires list permission
            if (auth()->user()->can('contact-messages-list')) {
                $operate .=
                    '<a href="javascript:void(0)" class="btn btn-info btn-xs view-message"
                    data-id="'
                    . $message->id
                    . '"
                    data-toggle="modal"
                    data-target="#viewMessageModal"
                    title="View Details"><i class="fa fa-eye"></i></a>&nbsp;&nbsp;';
            }

            // Update status button - requires edit permission
            if (auth()->user()->can('contact-messages-edit')) {
                $operate .=
                    '<a href="javascript:void(0)" class="btn btn-primary btn-xs update-status"
                    data-id="'
                    . $message->id
                    . '"
                    data-status="'
                    . $message->status
                    . '"
                    data-toggle="modal"
                    data-target="#updateStatusModal"
                    title="Update Status"><i class="fa fa-edit"></i></a>&nbsp;&nbsp;';
            }

            // Delete button - requires delete permission
            if (auth()->user()->can('contact-messages-delete')) {
                $operate .=
                    '<a href="javascript:void(0)" class="btn btn-danger btn-xs delete-message"
                    data-id="'
                    . $message->id
                    . '"
                    title="Delete"><i class="fa fa-trash"></i></a>';
            }

            // Truncate message for preview (50 chars)
            $messagePreview = strlen($message->message) > 50
                ? substr($message->message, 0, 50) . '...'
                : $message->message;

            return [
                'id' => $message->id,
                'first_name' => $message->first_name,
                'email' => $message->email,
                'message_preview' => $messagePreview,
                'status' => ucfirst($message->status),
                'created_at' => $message->created_at->format('Y-m-d H:i:s'),
                'operate' => $operate,
            ];
        });

        return response()->json([
            'error' => false,
            'message' => 'Contact messages retrieved successfully',
            'data' => $data->values()->toArray(),
            'code' => 200,
        ]);
    }

    /**
     * Get single contact message details
     */
    public function show(string|int $id)
    {
        ResponseService::noPermissionThenSendJson('contact-messages-list');

        $message = ContactMessage::findOrFail($id);

        // Auto-mark as read when viewed
        if ($message->status === 'new') {
            $message->markAsRead();
        }

        return response()->json([
            'error' => false,
            'message' => 'Contact message retrieved successfully',
            'data' => [
                'id' => $message->id,
                'first_name' => $message->first_name,
                'email' => $message->email,
                'message' => $message->message,
                'ip_address' => $message->ip_address,
                'user_agent' => $message->user_agent,
                'status' => $message->status,
                'status_label' => $message->status_label,
                'created_at' => $message->created_at->format('M d, Y h:i A'),
            ],
            'code' => 200,
        ]);
    }

    /**
     * Update contact message status
     */
    public function updateStatus(Request $request, string|int $id)
    {
        ResponseService::noPermissionThenSendJson('contact-messages-edit');

        $validated = $request->validate([
            'status' => 'required|in:new,read,replied,closed',
        ]);

        $message = ContactMessage::findOrFail($id);
        $oldStatus = $message->status;
        /** @var string $newStatus */
        $newStatus = $validated['status'];

        if ($oldStatus === $newStatus) {
            return response()->json([
                'error' => true,
                'message' => "Status is already set to {$newStatus}",
                'code' => 422,
            ], 422);
        }

        DB::beginTransaction();
        try {
            $message->update(['status' => $newStatus]);
            DB::commit();

            return response()->json([
                'error' => false,
                'message' => 'Contact message status updated successfully',
                'data' => $message->fresh(),
                'code' => 200,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => true,
                'message' => 'Failed to update status: ' . $e->getMessage(),
                'code' => 500,
            ], 500);
        }
    }

    /**
     * Delete contact message
     */
    public function destroy(string|int $id)
    {
        ResponseService::noPermissionThenSendJson('contact-messages-delete');

        $message = ContactMessage::findOrFail($id);

        DB::beginTransaction();
        try {
            $message->delete();
            DB::commit();

            return response()->json([
                'error' => false,
                'message' => 'Contact message deleted successfully',
                'code' => 200,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => true,
                'message' => 'Failed to delete message: ' . $e->getMessage(),
                'code' => 500,
            ], 500);
        }
    }
}

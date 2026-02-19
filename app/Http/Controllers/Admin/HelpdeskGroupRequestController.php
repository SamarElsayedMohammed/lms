<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HelpdeskGroupRequest;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class HelpdeskGroupRequestController extends Controller
{
    public function index(Request $request)
    {
        // If it's an AJAX request, return JSON data for the table
        if ($request->ajax() || $request->wantsJson()) {
            $query = HelpdeskGroupRequest::with(['group', 'user']);

            // Apply filters
            if ($request->filled('group_id')) {
                $query->where('group_id', $request->group_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('search')) {
                $search = trim($request->search);
                if (!empty($search)) {
                    $query->where(static function ($q) use ($search): void {
                        $q->whereHas('user', static function ($userQuery) use ($search): void {
                            $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                        })->orWhereHas('group', static function ($groupQuery) use ($search): void {
                            $groupQuery->where('name', 'like', "%{$search}%");
                        });
                    });
                }
            }

            $total = $query->count();
            $offset = $request->get('offset', 0);
            $limit = $request->get('limit', 10);

            $result = $query->skip($offset)->take($limit)->get();

            $rows = [];
            $no = 1;
            foreach ($result as $row) {
                $tempRow = [
                    'id' => (string) $row->id,
                    'group_name' => (string) ($row->group->name ?? 'N/A'),
                    'user_name' => (string) ($row->user->name ?? 'N/A'),
                    'user_email' => (string) ($row->user->email ?? 'N/A'),
                    'status' => (string) $row->status,
                    'created_at' => (string) $row->created_at,
                    'no' => (int) $no++,
                    'operate' => '<a href="#" class="view-request btn btn-info btn-sm" title="View Request"><i class="fa fa-eye"></i> View</a> <a href="#" class="update-status btn btn-warning btn-sm" title="Update Status"><i class="fa fa-edit"></i> Update</a>',
                ];

                $rows[] = $tempRow;
            }

            return response()->json([
                'total' => $total,
                'rows' => $rows,
            ]);
        }

        // For regular GET requests, return the view
        return view('admin.helpdesk.group-requests.index', ['type_menu' => 'help-desk']);
    }

    public function show($id)
    {
        $request = HelpdeskGroupRequest::with(['group', 'user'])->findOrFail($id);
        return view('admin.helpdesk.group-requests.show', compact('request'), ['type_menu' => 'help-desk']);
    }

    public function updateStatus(Request $request, $id)
    {
        // Add debugging
        Log::info('UpdateStatus called with ID: ' . $id);
        Log::info('Request data: ' . json_encode($request->all()));

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,approved,rejected',
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed: ' . json_encode($validator->errors()));
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $groupRequest = HelpdeskGroupRequest::findOrFail($id);
            $groupRequest->update(['status' => $request->status]);

            Log::info('Status updated successfully for ID: ' . $id);
            return ResponseService::successResponse('Status updated successfully');
        } catch (Throwable $th) {
            Log::error('Error updating status: ' . $th->getMessage());
            ResponseService::logErrorRedirect($th, 'HelpdeskGroupRequestController -> updateStatus()');
            return ResponseService::errorResponse();
        }
    }

    public function getDashboardData()
    {
        $totalRequests = HelpdeskGroupRequest::count();
        $pendingRequests = HelpdeskGroupRequest::where('status', 'pending')->count();
        $approvedRequests = HelpdeskGroupRequest::where('status', 'approved')->count();
        $rejectedRequests = HelpdeskGroupRequest::where('status', 'rejected')->count();

        return response()->json([
            'total_requests' => $totalRequests,
            'pending_requests' => $pendingRequests,
            'approved_requests' => $approvedRequests,
            'rejected_requests' => $rejectedRequests,
        ]);
    }
}

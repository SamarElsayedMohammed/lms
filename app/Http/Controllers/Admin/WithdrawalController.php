<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WithdrawalController extends Controller
{
    public function index(Request $request)
    {
        ResponseService::noPermissionThenRedirect('withdrawals-list');
        $type_menu = 'withdrawals';

        return view('admin.withdrawals.index', compact('type_menu'));
    }

    public function show($id)
    {
        ResponseService::noPermissionThenRedirect('withdrawals-list');
        $type_menu = 'withdrawals';

        // Only show user-side withdrawal requests
        $withdrawal = WithdrawalRequest::with(['user', 'processedBy'])->where(static function ($q): void {
            $q->where('entry_type', 'user')->orWhereNull('entry_type'); // Include old records without entry_type (treat as user)
        })->findOrFail($id);

        return view('admin.withdrawals.show', compact('type_menu', 'withdrawal'));
    }

    public function getWithdrawalData(Request $request)
    {
        ResponseService::noPermissionThenSendJson('withdrawals-list');
        // Filter to show only user-related withdrawal requests
        // Include records where entry_type is 'user' OR NULL (for backward compatibility with old records)
        $query = WithdrawalRequest::with([
            'user:id,name,email',
            'processedBy:id,name',
        ])->where(static function ($q): void {
            $q->where('entry_type', 'user')->orWhereNull('entry_type'); // Include old records without entry_type (treat as user)
        })->whereHas('user'); // Only include requests with valid users

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(static function ($q) use ($search): void {
                $q->whereHas('user', static function ($userQuery) use ($search): void {
                    $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Date filter
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $res = $query->orderBy('created_at', 'desc')->get();

        $res = $res->map(static function ($row) {
            $operate = BootstrapTableService::viewButton(route('admin.withdrawals.show', $row->id));

            if ($row->status === 'pending') {
                $operate .=
                    '<a href="javascript:void(0)" class="btn btn-primary btn-xs edit-data" data-toggle="modal" data-target="#updateStatusModal" data-id="'
                    . $row->id
                    . '" title="Update Status"><i class="fa fa-edit"></i></a>&nbsp;&nbsp;';
            }

            return [
                'id' => $row->id,
                'user_name' => $row->user ? $row->user->name : 'N/A',
                'user_email' => $row->user ? $row->user->email : 'N/A',
                'amount' => number_format($row->amount, 2),
                'status' => ucfirst((string) $row->status),
                'entry_type' => ucfirst($row->entry_type ?? 'user'),
                'payment_method' => ucwords(str_replace('_', ' ', $row->payment_method)),
                'created_at' => $row->created_at->format('Y-m-d H:i:s'),
                'operate' => $operate,
            ];
        })->filter(); // Remove any null entries

        // Return JSON response directly instead of using ResponseService which uses exit()
        return response()->json([
            'error' => false,
            'message' => 'Withdrawal requests retrieved successfully',
            'data' => $res->values()->toArray(),
            'code' => 200,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('withdrawals-process');
        // Make admin_notes required if status is rejected
        $rules = [
            'status' => 'required|in:pending,approved,rejected',
            'admin_notes' => 'nullable|string|max:1000',
        ];

        if ($request->status === 'rejected') {
            $rules['admin_notes'] = 'required|string|max:1000';
        }

        $request->validate($rules);

        // Only allow updating user-side withdrawal requests
        $withdrawal = WithdrawalRequest::with('user')
            ->where(static function ($q): void {
                $q->where('entry_type', 'user')->orWhereNull('entry_type'); // Include old records without entry_type (treat as user)
            })
            ->findOrFail($id);
        $oldStatus = $withdrawal->status;
        $newStatus = $request->status;

        if ($oldStatus === $newStatus) {
            return response()->json([
                'error' => true,
                'message' => 'Status is already set to ' . $newStatus,
                'code' => 422,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $withdrawal->update([
                'status' => $newStatus,
                'admin_notes' => $request->admin_notes,
                'processed_at' => now(),
                'processed_by' => auth()->id(),
            ]);

            // If rejected, refund the amount back to user's wallet
            if ($oldStatus === 'pending' && $newStatus === 'rejected') {
                WalletService::creditWallet(
                    $withdrawal->user_id,
                    $withdrawal->amount,
                    'withdrawal',
                    "Withdrawal request #{$withdrawal->id} rejected - Amount refunded",
                    $withdrawal->id,
                    \App\Models\WithdrawalRequest::class,
                    $withdrawal->entry_type ?? 'user', // Use the same entry_type as the withdrawal request
                );
            }

            DB::commit();

            return response()->json([
                'error' => false,
                'message' => 'Withdrawal request status updated successfully',
                'data' => $withdrawal->fresh(),
                'code' => 200,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => true,
                'message' => 'Failed to update withdrawal request status: ' . $e->getMessage(),
                'code' => 500,
            ], 500);
        }
    }
}

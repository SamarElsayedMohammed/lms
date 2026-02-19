<?php

namespace App\Http\Controllers;

use App\Models\Instructor;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\BootstrapTableService;
use App\Services\InstructorModeService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InstructorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        ResponseService::noPermissionThenRedirect('instructors-list');

        // In single instructor mode, show message instead of redirecting
        if (InstructorModeService::isSingleInstructorMode()) {
            $type_menu = 'instructor';

            return view('instructor.index', compact('type_menu'));
        }

        $instructors = Instructor::with('user')->get();
        $type_menu = 'instructor';

        return view('instructor.index', compact('instructors', 'type_menu'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // In single instructor mode, return empty result
        if (InstructorModeService::isSingleInstructorMode()) {
            return response()->json([
                'total' => 0,
                'rows' => [],
            ]);
        }

        ResponseService::noPermissionThenSendJson('instructors-list');

        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $showDeleted = request('show_deleted');

        $sql = Instructor::with(['user'])->when(!empty($search), static function ($query) use ($search): void {
            $query->whereHas('user', static function ($q) use ($search): void {
                $q->where('name', 'LIKE', "%$search%")->orWhere('email', 'LIKE', "%$search%");
            });
        })->when(!empty($showDeleted), static function ($query): void {
            $query->onlyTrashed();
        });

        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = $offset + 1;

        foreach ($res as $row) {
            $operate = '';
            if (auth()->user()->can('instructors-status-update')) {
                $operate .= BootstrapTableService::button(
                    'fas fa-check',
                    '',
                    ['btn-info', 'change-status'],
                    [
                        'title' => 'Change Status',
                        'data-target' => '#instructorEditModal',
                        'data-toggle' => 'modal',
                        'data-id' => $row->id,
                    ],
                );
            }
            if (auth()->user()->can('instructors-show-form')) {
                $operate .= BootstrapTableService::button(
                    'fas fa-eye',
                    route('instructor.show-form', $row->id),
                    ['btn', 'icon', 'btn-primary', 'view-form-btn'],
                    ['title' => 'View Form'],
                );
            }
            $statusBadge = $this->getStatusBadge($row->status);
            $typeBadge = $this->getTypeBadge($row->type);
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['edit_url'] = route('instructor.status.update', $row->id);
            $tempRow['operate'] = $operate;
            $tempRow['status_value'] = $row->status; // Store actual status value for modal
            $tempRow['reason'] = $row->reason; // Store reason for modal
            $tempRow['status'] = $statusBadge; // Display badge
            $tempRow['type'] = $typeBadge;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;

        return response()->json($bulkData);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
    }

    public function updateStatus(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('instructors-status-update');

        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:approved,rejected,suspended',
                'reason' => 'nullable|string',
            ]);
            if ($validator->fails()) {
                return ResponseService::validationError($validator->errors()->first());
            }
            $data = $validator->validated();
            $instructor = Instructor::find($id);
            $oldStatus = $instructor->status;
            $instructor->status = $data['status'];
            $instructor->reason =
                $data['status'] == 'rejected' || $data['status'] == 'suspended' ? $data['reason'] : null;
            $instructor->save();

            // Update User Role to instructor
            $user = User::find($instructor->user_id);
            $user->assignRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));
            $user->save();

            // Send notification to instructor if status changed to approved or rejected
            if (
                ($data['status'] == 'approved' || $data['status'] == 'rejected' || $data['status'] == 'suspended')
                && $oldStatus != $data['status']
            ) {
                $user->notify(
                    new \App\Notifications\InstructorStatusUpdateNotification(
                        $instructor,
                        $data['status'],
                        $data['reason'] ?? null,
                    ),
                );
            }

            return ResponseService::successResponse('Status updated successfully');
        } catch (\Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function showForm($id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|exists:instructors,id',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->errors()->first());
        }
        ResponseService::noPermissionThenSendJson('instructors-list');
        $instructor = Instructor::with([
            'user',
            'personal_details',
            'social_medias' => static function ($socialMediaQuery): void {
                $socialMediaQuery->with('social_media:id,name');
            },
            'other_details' => static function ($otherDetailQuery): void {
                $otherDetailQuery->with([
                    'custom_form_field:id,name,type',
                    'custom_form_field.options:id,option,custom_form_field_id',
                    'custom_form_field_option:id,option,custom_form_field_id',
                ]);
            },
        ])->find($id);

        if (!$instructor) {
            return redirect()->route('instructor.index')->with('error', 'Instructor not found');
        }

        $type_menu = 'instructor';

        return view('instructor.form-details', compact('instructor', 'type_menu'));
    }

    /**
     * Display instructor wallet history
     */
    public function walletHistory()
    {
        ResponseService::noPermissionThenRedirect('instructors-list');

        // In single instructor mode, redirect to dashboard
        if (InstructorModeService::isSingleInstructorMode()) {
            return redirect()
                ->route('dashboard')
                ->with('info', 'Instructor wallet history is disabled in Single Instructor mode.');
        }

        $type_menu = 'instructor-wallet';

        // Get only instructors who have wallet history entries
        $instructors = User::whereHas('roles', static function ($query): void {
            $query->where('name', 'Instructor');
        })
            ->whereHas('walletHistories', static function ($query): void {
                $query->where('entry_type', 'instructor');
            })
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return view('instructor.wallet-history', compact('type_menu', 'instructors'));
    }

    /**
     * Get wallet history data for DataTable
     */
    public function getWalletHistoryData(Request $request)
    {
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'DESC');
        $search = $request->input('search');

        // New filters
        $transactionType = $request->input('transaction_type'); // credit/debit
        $instructorId = $request->input('instructor_id');

        $sql = \App\Models\WalletHistory::with(['user'])
            ->where('entry_type', 'instructor') // Show only instructor-side entries
            ->when(!empty($search), static function ($q) use ($search): void {
                $q->where(static function ($query) use ($search): void {
                    $query
                        ->whereHas('user', static function ($userQuery) use ($search): void {
                            $userQuery->where('name', 'LIKE', "%$search%")->orWhere('email', 'LIKE', "%$search%");
                        })
                        ->orWhere('description', 'LIKE', "%$search%")
                        ->orWhere('type', 'LIKE', "%$search%")
                        ->orWhere('amount', 'LIKE', "%$search%");
                });
            })
            ->when(
                !empty($transactionType) && in_array($transactionType, ['credit', 'debit']),
                static function ($q) use ($transactionType): void {
                    // Filter by type field (enum: 'credit' or 'debit')
                    $q->where('type', $transactionType);
                },
            )
            ->when(!empty($instructorId), static function ($q) use ($instructorId): void {
                $q->where('user_id', $instructorId);
            });

        $sql->orderBy($sort, $order);

        $total = $sql->count();
        $result = $sql->skip($offset)->take($limit)->get();

        $rows = [];
        $no = $offset + 1;
        foreach ($result as $row) {
            $isCredit = $row->amount > 0;
            $amountClass = $isCredit ? 'text-success' : 'text-danger';
            $amountPrefix = $isCredit ? '+' : '';

            $entryTypeBadge = $row->entry_type === 'instructor'
                ? 'warning'
                : ($row->entry_type === 'user' ? 'primary' : 'info');
            $entryType = ucfirst($row->entry_type ?? 'instructor');

            $tempRow = [
                'id' => $row->id,
                'no' => $no++,
                'instructor_name' => $row->user->name ?? 'N/A',
                'instructor_email' => $row->user->email ?? 'N/A',
                'type' => ucfirst((string) $row->type),
                'transaction_type' => ucwords(str_replace('_', ' ', $row->transaction_type ?? '')),
                'entry_type' => '<span class="badge badge-' . $entryTypeBadge . '">' . $entryType . '</span>',
                'amount' =>
                    '<span class="'
                    . $amountClass
                    . '">'
                    . $amountPrefix
                    . '₹'
                    . number_format(abs($row->amount), 2)
                    . '</span>',
                'description' => $row->description,
                'created_at' => $row->created_at->format('d M Y, h:i A'),
            ];

            $rows[] = $tempRow;
        }

        return response()->json([
            'total' => $total,
            'rows' => $rows,
        ]);
    }

    /**
     * Display withdrawal requests page
     */
    public function withdrawalRequests(Request $request)
    {
        ResponseService::noPermissionThenRedirect('instructors-list');

        // In single instructor mode, redirect to dashboard
        if (InstructorModeService::isSingleInstructorMode()) {
            return redirect()
                ->route('dashboard')
                ->with('info', 'Instructor withdrawal requests are disabled in Single Instructor mode.');
        }

        $type_menu = 'instructor-withdrawals';

        // Get all instructors for filter dropdown
        $instructors = User::whereHas('roles', static function ($query): void {
            $query->where('name', 'Instructor');
        })
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        // Get filtered withdrawal requests
        $query = WithdrawalRequest::with(['user:id,name,email', 'processedBy:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('instructor_id')) {
            $query->where('user_id', $request->instructor_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(static function ($q) use ($search): void {
                $q
                    ->whereHas('user', static function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'LIKE', "%{$search}%")->orWhere('email', 'LIKE', "%{$search}%");
                    })
                    ->orWhere('amount', 'LIKE', "%{$search}%")
                    ->orWhere('payment_method', 'LIKE', "%{$search}%")
                    ->orWhere('notes', 'LIKE', "%{$search}%");
            });
        }

        $withdrawalRequests = $query->orderBy('created_at', 'desc')->paginate(15);

        // Get summary statistics
        $summary = [
            'pending_count' => WithdrawalRequest::where('status', 'pending')->count(),
            'approved_count' => WithdrawalRequest::where('status', 'approved')->count(),
            'rejected_count' => WithdrawalRequest::where('status', 'rejected')->count(),
            'total_amount' => WithdrawalRequest::sum('amount'),
        ];

        return view('instructor.withdrawal-requests', compact(
            'type_menu',
            'instructors',
            'withdrawalRequests',
            'summary',
        ));
    }

    /**
     * Get withdrawal requests data for DataTable
     */
    public function getWithdrawalRequestsData(Request $request)
    {
        // Log request for debugging
        // Log::info('getWithdrawalRequestsData called with params:', $request->all());

        // Check if this is a details request
        if ($request->input('action') === 'details' && $request->input('withdrawal_request_id')) {
            // Log::info('Processing details request for ID:', $request->input('withdrawal_request_id'));
            return $this->getWithdrawalRequestDetails($request);
        }

        // Check if this is a summary request
        if ($request->input('action') === 'summary') {
            return $this->getWithdrawalRequestSummary();
        }

        $limit = $request->input('limit', 10);
        $offset = $request->input('offset', 0);
        $search = $request->input('search');
        $sort = $request->input('sort', 'created_at');
        $order = $request->input('order', 'desc');
        $status = $request->input('status');
        $instructorId = $request->input('instructor_id');

        $sql = WithdrawalRequest::with(['user:id,name,email', 'processedBy:id,name'])
            ->when(!empty($search), static function ($q) use ($search): void {
                $q
                    ->whereHas('user', static function ($query) use ($search): void {
                        $query->where('name', 'LIKE', "%$search%")->orWhere('email', 'LIKE', "%$search%");
                    })
                    ->orWhere('amount', 'LIKE', "%$search%")
                    ->orWhere('payment_method', 'LIKE', "%$search%")
                    ->orWhere('notes', 'LIKE', "%$search%");
            })
            ->when(!empty($status), static function ($q) use ($status): void {
                $q->where('status', $status);
            })
            ->when(!empty($instructorId), static function ($q) use ($instructorId): void {
                $q->where('user_id', $instructorId);
            });

        $sql->orderBy($sort, $order);

        $total = $sql->count();
        $result = $sql->skip($offset)->take($limit)->get();

        $rows = [];
        $no = $offset + 1;
        foreach ($result as $row) {
            $statusBadge = $this->getStatusBadge($row->status);

            $paymentMethodLabel = ucwords(str_replace('_', ' ', $row->payment_method));

            $tempRow = [
                'id' => $row->id,
                'no' => $no++,
                'instructor_name' => $row->user->name ?? 'N/A',
                'instructor_email' => $row->user->email ?? 'N/A',
                'amount' => '₹' . number_format($row->amount, 2),
                'status' => $statusBadge,
                'payment_method' => $paymentMethodLabel,
                'notes' => $row->notes ?? '-',
                'admin_notes' => $row->admin_notes ?? '-',
                'created_at' => $row->created_at->format('d M Y, h:i A'),
                'processed_at' => $row->processed_at ? $row->processed_at->format('d M Y, h:i A') : '-',
                'processed_by' => $row->processedBy->name ?? '-',
                'actions' => $this->getActionButtons($row),
            ];

            $rows[] = $tempRow;
        }

        return response()->json([
            'total' => $total,
            'rows' => $rows,
        ]);
    }

    /**
     * Get withdrawal request details
     */
    private function getWithdrawalRequestDetails(Request $request)
    {
        $withdrawalRequestId = $request->input('withdrawal_request_id');
        // Log::info('Getting details for withdrawal request ID:', $withdrawalRequestId);

        $withdrawalRequest = WithdrawalRequest::with(['user:id,name,email', 'processedBy:id,name'])->findOrFail(
            $withdrawalRequestId,
        );

        // Log::info('Found withdrawal request:', $withdrawalRequest->toArray());

        $statusBadge = $this->getStatusBadge($withdrawalRequest->status);
        $paymentMethodLabel = ucwords(str_replace('_', ' ', $withdrawalRequest->payment_method));

        $data = [
            'id' => $withdrawalRequest->id,
            'status' => $withdrawalRequest->status,
            'status_badge' => $statusBadge,
            'instructor_name' => $withdrawalRequest->user->name ?? 'N/A',
            'instructor_email' => $withdrawalRequest->user->email ?? 'N/A',
            'amount' => number_format($withdrawalRequest->amount, 2),
            'payment_method' => $withdrawalRequest->payment_method,
            'payment_method_label' => $paymentMethodLabel,
            'payment_details' => $withdrawalRequest->payment_details,
            'notes' => $withdrawalRequest->notes,
            'admin_notes' => $withdrawalRequest->admin_notes,
            'created_at' => $withdrawalRequest->created_at->format('d M Y, h:i A'),
            'processed_at' => $withdrawalRequest->processed_at
                ? $withdrawalRequest->processed_at->format('d M Y, h:i A')
                : null,
            'processed_by' => $withdrawalRequest->processedBy->name ?? null,
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get withdrawal request summary
     */
    private function getWithdrawalRequestSummary()
    {
        $summary = [
            'pending_count' => WithdrawalRequest::where('status', 'pending')->count(),
            'approved_count' => WithdrawalRequest::where('status', 'approved')->count(),
            'rejected_count' => WithdrawalRequest::where('status', 'rejected')->count(),
            'processing_count' => WithdrawalRequest::where('status', 'processing')->count(),
            'completed_count' => WithdrawalRequest::where('status', 'completed')->count(),
            'total_amount' => WithdrawalRequest::sum('amount'),
            'pending_amount' => WithdrawalRequest::where('status', 'pending')->sum('amount'),
            'approved_amount' => WithdrawalRequest::where('status', 'approved')->sum('amount'),
            'rejected_amount' => WithdrawalRequest::where('status', 'rejected')->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * Update withdrawal request status
     */
    public function updateWithdrawalRequestStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'withdrawal_request_id' => 'required|exists:withdrawal_requests,id',
            'status' => 'required|in:pending,approved,rejected,processing,completed',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return ResponseService::errorResponse($validator->errors()->first());
        }

        try {
            $withdrawalRequest = WithdrawalRequest::with('user')->findOrFail($request->withdrawal_request_id);
            $oldStatus = $withdrawalRequest->status;
            $newStatus = $request->status;

            if ($oldStatus === $newStatus) {
                return ResponseService::errorResponse('Status is already set to ' . $newStatus);
            }

            DB::beginTransaction();

            // Update withdrawal request
            $withdrawalRequest->update([
                'status' => $newStatus,
                'admin_notes' => $request->admin_notes,
                'processed_at' => now(),
                'processed_by' => Auth::id(),
            ]);

            // Handle wallet operations based on status change
            if ($oldStatus === 'pending' && $newStatus === 'rejected') {
                // Refund the amount back to user's wallet
                \App\Services\WalletService::creditWallet(
                    $withdrawalRequest->user_id,
                    $withdrawalRequest->amount,
                    'withdrawal',
                    "Withdrawal request #{$withdrawalRequest->id} rejected - Amount refunded",
                    $withdrawalRequest->id,
                    \App\Models\WithdrawalRequest::class,
                    'instructor', // Instructor-side entry
                );
            }

            DB::commit();

            return ResponseService::successResponse('Withdrawal request status updated successfully');
        } catch (\Throwable $e) {
            DB::rollBack();

            return ResponseService::errorResponse('Failed to update withdrawal request status: ' . $e->getMessage());
        }
    }

    /**
     * Get status badge HTML
     */
    private function getStatusBadge($status)
    {
        $badges = [
            'pending' => '<span class="badge badge-warning">Pending</span>',
            'approved' => '<span class="badge badge-success">Approved</span>',
            'rejected' => '<span class="badge badge-danger">Rejected</span>',
            'suspended' => '<span class="badge badge-info">Suspended</span>',
            'completed' => '<span class="badge badge-primary">Completed</span>',
        ];

        return $badges[$status] ?? '<span class="badge badge-secondary">Unknown</span>';
    }

    private function getTypeBadge($type)
    {
        $badges = [
            'team' => '<span class="badge badge-success">Team</span>',
            'individual' => '<span class="badge badge-danger">Individual</span>',
        ];

        return $badges[$type] ?? '<span class="badge badge-secondary">Unknown</span>';
    }

    /**
     * Get action buttons HTML
     */
    private function getActionButtons($row)
    {
        $buttons = '';

        // View details button
        $buttons .=
            '<button class="btn btn-sm btn-info view-details" data-id="' . $row->id . '" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button> ';

        // Status update buttons based on current status
        if ($row->status === 'pending') {
            $buttons .=
                '<button class="btn btn-sm btn-success approve-request" data-id="'
                . $row->id
                . '" title="Approve">
                            <i class="fas fa-check"></i>
                        </button> ';
            $buttons .=
                '<button class="btn btn-sm btn-danger reject-request" data-id="'
                . $row->id
                . '" title="Reject">
                            <i class="fas fa-times"></i>
                        </button> ';
        } elseif ($row->status === 'approved') {
            $buttons .=
                '<button class="btn btn-sm btn-info process-request" data-id="'
                . $row->id
                . '" title="Mark as Processing">
                            <i class="fas fa-cog"></i>
                        </button> ';
        } elseif ($row->status === 'processing') {
            $buttons .=
                '<button class="btn btn-sm btn-primary complete-request" data-id="'
                . $row->id
                . '" title="Mark as Completed">
                            <i class="fas fa-check-circle"></i>
                        </button> ';
        }

        return $buttons;
    }
}

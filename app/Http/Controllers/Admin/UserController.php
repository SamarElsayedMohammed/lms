<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\User;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['users-list', 'manage_accounts']);
        return view('admin.users.index', ['type_menu' => 'users']);
    }

    /**
     * Display the specified resource data for table.
     */
    public function show(Request $request)
    {
        $offset = $request->offset ?? 0;
        $limit = $request->limit ?? 10;
        $sort = $request->sort ?? 'id';
        $order = $request->order ?? 'DESC';
        $search = $request->search ?? '';

        $query = User::with('instructor_details')->orderBy($sort, $order);

        // Apply search filter
        if (!empty($search)) {
            $query->where(static function ($q) use ($search): void {
                $q
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('mobile', 'like', '%' . $search . '%')
                    ->orWhere('slug', 'like', '%' . $search . '%');
            });
        }

        $total = $query->count();
        $result = $query->skip($offset)->take($limit)->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = $offset + 1;

        foreach ($result as $row) {
            $operate = '';
            // Create view button manually
            $viewUrl = route('admin.users.details', $row->id);
            $operate .=
                '<a href="'
                . $viewUrl
                . '" class="btn icon btn-xs btn-rounded btn-icon rounded-pill btn-info view_btn" title="View" data-target="#userDetailsModal" data-toggle="modal" id="'
                . $row->id
                . '"><i class="fas fa-eye"></i></a>&nbsp;&nbsp;';

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['status'] = 1;
            $tempRow['is_active'] = $row->is_active ?? 0;
            // Add export column for is_active
            $tempRow['is_active_export'] =
                $tempRow['is_active'] == 1
                || $tempRow['is_active'] === 1
                || $tempRow['is_active'] === '1'
                || $tempRow['is_active'] === true
                    ? 'Active'
                    : 'Deactive';
            $tempRow['type'] = $row->type ?? 'N/A';
            $tempRow['is_instructor'] = !empty($row->instructor_details) ? 1 : 0;
            $tempRow['instructor_status'] = $row->instructor_details->status ?? null;
            $tempRow['operate'] = $operate;
            // Format dates to readable local format
            $tempRow['created_at'] = $row->created_at ? $row->created_at->format('M d, Y h:i A') : 'N/A';
            $tempRow['updated_at'] = $row->updated_at ? $row->updated_at->format('M d, Y h:i A') : 'N/A';
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    /**
     * Get user details
     */
    public function details($id)
    {
        $user = User::with(['roles'])->findOrFail($id);

        // Format dates to readable local format
        $userData = $user->toArray();
        $userData['created_at'] = $user->created_at ? $user->created_at->format('M d, Y h:i A') : 'N/A';
        $userData['updated_at'] = $user->updated_at ? $user->updated_at->format('M d, Y h:i A') : 'N/A';
        if ($user->deleted_at) {
            $userData['deleted_at'] = $user->deleted_at->format('M d, Y h:i A');
        }

        return response()->json($userData);
    }

    /**
     * Toggle user status (active/inactive)
     */
    public function toggleStatus(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $user = User::findOrFail($id);
            $newStatus = $user->is_active ? 0 : 1;

            // Update user status
            $user->update(['is_active' => $newStatus]);

            // If user is an instructor, also update instructor status
            $instructor = Instructor::where('user_id', $user->id)->first();
            $instructorUpdated = false;

            if ($instructor) {
                // If activating user, set instructor status to 'approved' if it was 'suspended'
                // If deactivating user, set instructor status to 'suspended'
                if ($newStatus == 1) {
                    // Activating: if suspended, change to approved
                    if ($instructor->status == 'suspended') {
                        $instructor->update(['status' => 'approved']);
                        $instructorUpdated = true;
                    }
                } else {
                    // Deactivating: change to suspended
                    if ($instructor->status == 'approved' || $instructor->status == 'pending') {
                        $instructor->update(['status' => 'suspended']);
                        $instructorUpdated = true;
                    }
                }
            }

            DB::commit();

            $message = $newStatus ? 'User activated successfully' : 'User deactivated successfully';
            if ($instructorUpdated) {
                $message .= '. Instructor status has also been updated.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'is_active' => $newStatus,
                'is_instructor' => $instructor ? true : false,
                'instructor_status' => $instructor ? $instructor->status : null,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user status: ' . $e->getMessage(),
            ], 500);
        }
    }
}

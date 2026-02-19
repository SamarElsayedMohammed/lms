<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WalletHistory;
use App\Services\ResponseService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['wallets-list', 'manage_finances']);
        $type_menu = 'wallets';

        return view('admin.wallets.index', compact('type_menu'));
    }

    public function getWalletData(Request $request)
    {
        ResponseService::noAnyPermissionThenSendJson(['wallets-list', 'manage_finances']);
        // Filter to show only user-related transactions (entry_type = 'user')
        $query = WalletHistory::with('user:id,name,email')->where('entry_type', 'user'); // Show only user-side entries

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(static function ($q) use ($search): void {
                $q
                    ->where('transaction_type', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('user', static function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Type filter
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Transaction type filter
        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        // Date filter
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $res = $query->orderBy('created_at', 'desc')->get();

        $res = $res->map(static fn($row) => [
            'id' => $row->id,
            'user_name' => $row->user->name ?? 'N/A',
            'user_email' => $row->user->email ?? 'N/A',
            'amount' => number_format($row->amount, 2),
            'type' => ucfirst((string) $row->type),
            'transaction_type' => ucwords(str_replace('_', ' ', $row->transaction_type)),
            'entry_type' => ucfirst($row->entry_type ?? 'user'),
            'description' => $row->description ?? '-',
            'balance_before' => number_format($row->balance_before, 2),
            'balance_after' => number_format($row->balance_after, 2),
            'created_at' => $row->created_at->format('Y-m-d H:i:s'),
        ]);

        // Return JSON response directly instead of using ResponseService which uses exit()
        return response()->json([
            'error' => false,
            'message' => 'Wallet history retrieved successfully',
            'data' => $res->values()->toArray(),
            'code' => 200,
        ]);
    }
}

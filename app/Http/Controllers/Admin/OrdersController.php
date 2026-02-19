<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrdersController extends Controller
{
    public function index(Request $request)
    {
        ResponseService::noPermissionThenRedirect('orders-list');
        $query = Order::with(['user', 'orderCourses.course', 'promoCode'])->orderBy('created_at', 'desc');

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(static function ($q) use ($search): void {
                $q
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$search}%")
                    ->orWhereHas('user', static function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $orders = $query->paginate(15);

        // Get summary statistics
        $stats = [
            'total_orders' => Order::count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'completed_orders' => Order::where('status', 'completed')->count(),
            'cancelled_orders' => Order::where('status', 'cancelled')->count(),
            'total_revenue' => Order::where('status', 'completed')->sum('final_price'),
            'today_orders' => Order::whereDate('created_at', today())->count(),
        ];

        return view('pages.admin.orders.index', compact('orders', 'stats'), ['type_menu' => 'orders']);
    }

    public function show($id)
    {
        $order = Order::with([
            'user',
            'orderCourses.course.user',
            'promoCode',
            'paymentTransaction',
        ])->findOrFail($id);

        return view('pages.admin.orders.show', compact('order'), ['type_menu' => 'orders']);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,completed,cancelled,failed',
        ]);

        $order = Order::findOrFail($id);
        $oldStatus = $order->status;
        $order->update(['status' => $request->status]);

        // If status changed to completed, create curriculum tracking entries
        if ($request->status === 'completed' && $oldStatus !== 'completed') {
            $order->load('user');
            if ($order->user) {
                \App\Services\OrderTrackingService::createCurriculumTrackingEntries($order, $order->user);
            }
        }

        // Return JSON response for AJAX requests
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully.',
            ]);
        }

        return redirect()->back()->with('success', 'Order status updated successfully.');
    }

    public function getDashboardData()
    {
        $data = [
            'recent_orders' => Order::with('user')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(),
            'status_counts' => Order::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status'),
            'monthly_revenue' => Order::where('status', 'completed')->whereMonth('created_at', now()->month)->sum(
                'final_price',
            ),
            'daily_orders' => Order::whereDate('created_at', today())->count(),
        ];

        return response()->json($data);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Order;
use App\Services\OrderTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('orders-list');

        $query = Order::with(['user', 'orderCourses.course', 'promoCode'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->payment_method, fn ($q) => $q->where('payment_method', $request->payment_method))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $s = $request->search;
                $q->where('order_number', 'like', "%{$s}%")
                    ->orWhere('id', 'like', "%{$s}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
            }));

        $perPage = min((int) $request->input('per_page', 15), 100);
        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $stats = [
            'total_orders' => Order::count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'completed_orders' => Order::where('status', 'completed')->count(),
            'cancelled_orders' => Order::where('status', 'cancelled')->count(),
            'total_revenue' => Order::where('status', 'completed')->sum('final_price'),
            'today_orders' => Order::whereDate('created_at', today())->count(),
        ];

        return $this->jsonSuccess(__('Orders retrieved'), [
            'orders' => $orders,
            'stats' => $stats,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('orders-list');

        $order = Order::with(['user', 'orderCourses.course.user', 'promoCode', 'paymentTransaction'])->find($id);
        if (!$order) {
            return $this->jsonError(__('Order not found'), 404);
        }

        return $this->jsonSuccess(__('Order retrieved'), $order);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('orders-list');

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,completed,cancelled,failed',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $order = Order::find($id);
        if (!$order) {
            return $this->jsonError(__('Order not found'), 404);
        }

        $oldStatus = $order->status;
        $order->update(['status' => $request->status]);

        if ($request->status === 'completed' && $oldStatus !== 'completed') {
            $order->load('user');
            if ($order->user) {
                OrderTrackingService::createCurriculumTrackingEntries($order, $order->user);
            }
        }

        return $this->jsonSuccess(__('Order status updated successfully'), $order->fresh());
    }
}

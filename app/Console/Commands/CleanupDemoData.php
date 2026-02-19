<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Models\Course\Course;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\Rating;
use App\Models\RefundRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletHistory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDemoData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demo:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up demo data entries older than 8 hours (created after 12/11/2025)';

    /**
     * Demo data cutoff date - entries before this date are protected
     */
    private $demoDataCutoffDate;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Check if demo mode is enabled
        if (config('app.demo_mode') != 1 && env('DEMO_MODE') != 1) {
            $this->info('Demo mode is not enabled. Skipping cleanup.');
            return 0;
        }

        $this->demoDataCutoffDate = Carbon::parse('2025-11-12 00:00:00');
        $cutoffTime = Carbon::now()->subHours(8);

        $this->info('Starting demo data cleanup...');
        $this->info('Demo data cutoff date: ' . $this->demoDataCutoffDate->format('Y-m-d H:i:s'));
        $this->info('Deleting entries created after cutoff date and older than 8 hours...');

        DB::beginTransaction();

        try {
            $deletedCount = 0;

            // Cleanup Orders and related data
            $deletedCount += $this->cleanupOrders($cutoffTime);

            // Cleanup Transactions
            $deletedCount += $this->cleanupTransactions($cutoffTime);

            // Cleanup Carts
            $deletedCount += $this->cleanupCarts($cutoffTime);

            // Cleanup Ratings
            $deletedCount += $this->cleanupRatings($cutoffTime);

            // Cleanup Refund Requests
            $deletedCount += $this->cleanupRefundRequests($cutoffTime);

            // Cleanup Wallet History
            $deletedCount += $this->cleanupWalletHistory($cutoffTime);

            // Cleanup Users (except demo users)
            $deletedCount += $this->cleanupUsers($cutoffTime);

            // Cleanup Courses (except demo courses)
            $deletedCount += $this->cleanupCourses($cutoffTime);

            DB::commit();

            $this->info("Cleanup completed successfully. Total records deleted: {$deletedCount}");
            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error during cleanup: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Cleanup Orders created after demo cutoff and older than 8 hours
     */
    private function cleanupOrders($cutoffTime)
    {
        $orders = Order::where('created_at', '>', $this->demoDataCutoffDate)->where(
            'created_at',
            '<',
            $cutoffTime,
        )->get();

        $count = 0;
        foreach ($orders as $order) {
            // Delete related order courses
            OrderCourse::where('order_id', $order->id)->delete();

            // Delete the order
            $order->delete();
            $count++;
        }

        $this->info("Deleted {$count} orders and related data.");
        return $count;
    }

    /**
     * Cleanup Transactions
     */
    private function cleanupTransactions($cutoffTime)
    {
        $count = Transaction::where('created_at', '>', $this->demoDataCutoffDate)
            ->where('created_at', '<', $cutoffTime)
            ->delete();

        $this->info("Deleted {$count} transactions.");
        return $count;
    }

    /**
     * Cleanup Carts
     */
    private function cleanupCarts($cutoffTime)
    {
        $count = Cart::where('created_at', '>', $this->demoDataCutoffDate)
            ->where('created_at', '<', $cutoffTime)
            ->delete();

        $this->info("Deleted {$count} cart items.");
        return $count;
    }

    /**
     * Cleanup Ratings
     */
    private function cleanupRatings($cutoffTime)
    {
        $count = Rating::where('created_at', '>', $this->demoDataCutoffDate)
            ->where('created_at', '<', $cutoffTime)
            ->delete();

        $this->info("Deleted {$count} ratings.");
        return $count;
    }

    /**
     * Cleanup Refund Requests
     */
    private function cleanupRefundRequests($cutoffTime)
    {
        $count = RefundRequest::where('created_at', '>', $this->demoDataCutoffDate)
            ->where('created_at', '<', $cutoffTime)
            ->delete();

        $this->info("Deleted {$count} refund requests.");
        return $count;
    }

    /**
     * Cleanup Wallet History
     */
    private function cleanupWalletHistory($cutoffTime)
    {
        $count = WalletHistory::where('created_at', '>', $this->demoDataCutoffDate)
            ->where('created_at', '<', $cutoffTime)
            ->delete();

        $this->info("Deleted {$count} wallet history records.");
        return $count;
    }

    /**
     * Cleanup Users (except demo users created before cutoff)
     */
    private function cleanupUsers($cutoffTime)
    {
        $count = User::where('created_at', '>', $this->demoDataCutoffDate)
            ->where('created_at', '<', $cutoffTime)
            ->whereDoesntHave('roles', static function ($query): void {
                $query->where('name', 'Admin');
            })
            ->delete();

        $this->info("Deleted {$count} users (excluding admins and demo users).");
        return $count;
    }

    /**
     * Cleanup Courses (except demo courses created before cutoff)
     */
    private function cleanupCourses($cutoffTime)
    {
        $count = Course::where('created_at', '>', $this->demoDataCutoffDate)
            ->where('created_at', '<', $cutoffTime)
            ->delete();

        $this->info("Deleted {$count} courses (excluding demo courses).");
        return $count;
    }
}

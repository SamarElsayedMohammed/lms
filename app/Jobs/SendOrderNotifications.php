<?php

namespace App\Jobs;

use App\Helpers\FirebaseHelper;
use App\Models\Order;
use App\Models\UserFcmToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendOrderNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $order;

    public function __construct(
        Order $order,
        public $user,
    ) {
        $this->order = $order;
    }

    public function handle()
    {
        Log::info('🚀 SendOrderNotifications job started', [
            'order_id' => $this->order->id,
            'user_id' => $this->user->id,
        ]);

        try {
            $fcmData = [
                'title' => 'Order Placed',
                'body' => "Order #{$this->order->order_number} has been placed successfully.",
                'type' => 'new_order',
                'order_id' => (string) $this->order->id,
            ];

            // 🔔 Send to User
            try {
                $tokens = UserFcmToken::where('user_id', $this->user->id)->pluck('fcm_token');
                Log::info('User tokens found', ['count' => $tokens->count()]);
                foreach ($tokens as $token) {
                    Log::debug('Sending FCM to user', ['token' => $token]);
                    FirebaseHelper::send('android', $token, $fcmData, true);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to send FCM to user', [
                    'user_id' => $this->user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // 🔔 Send to Admin
            try {
                $adminTokens = UserFcmToken::where('user_id', 1)->pluck('fcm_token');
                Log::info('Admin tokens found', ['count' => $adminTokens->count()]);
                foreach ($adminTokens as $token) {
                    Log::debug('Sending FCM to admin', ['token' => $token]);
                    FirebaseHelper::send('web', $token, $fcmData, true);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to send FCM to admin', [
                    'error' => $e->getMessage(),
                ]);
            }

            // 🔔 Send to Instructors
            try {
                $instructors = $this->order
                    ->orderCourses()
                    ->with('course.user')
                    ->get()
                    ->pluck('course.user')
                    ->unique();
                Log::info('Instructors found', ['count' => $instructors->count()]);
                foreach ($instructors as $instructor) {
                    $tokens = UserFcmToken::where('user_id', $instructor->id)->pluck('fcm_token');
                    Log::info('Instructor tokens', ['instructor_id' => $instructor->id, 'count' => $tokens->count()]);
                    foreach ($tokens as $token) {
                        Log::debug('Sending FCM to instructor', ['token' => $token]);
                        FirebaseHelper::send('ios', $token, $fcmData, true);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to send FCM to instructors', [
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('✅ SendOrderNotifications job finished', [
                'order_id' => $this->order->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('❌ SendOrderNotifications job failed', [
                'order_id' => $this->order->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't rethrow - let the job complete successfully even if notifications fail
            // This ensures orders are not marked as failed due to Firebase issues
        }
    }
}

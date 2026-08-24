<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStoreNotificationJob;
use App\Models\StoreNotificationEvent;
use App\Services\Payment\AppleStoreBillingService;
use App\Services\Payment\StoreSubscriptionLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AppleStoreServerNotificationController extends Controller
{
    public function __construct(
        private readonly AppleStoreBillingService $appleService,
        private readonly StoreSubscriptionLifecycleService $lifecycleService,
    ) {
    }

    /**
     * Handle Apple App Store Server Notifications V2
     * POST /api/webhooks/apple/app-store
     */
    public function handle(Request $request): JsonResponse
    {
        if (! config('store_billing.enabled', false) || ! config('store_billing.notifications_enabled', false)) {
            Log::info('Apple Webhook: Store notifications disabled, ignoring payload without mutation.');
            return response()->json(['status' => 'disabled', 'message' => 'Apple store notifications are currently disabled.'], 200);
        }

        $signedPayload = (string) $request->input('signedPayload', '');
        if (trim($signedPayload) === '') {
            Log::warning('Apple Webhook: Empty or missing signedPayload');
            return response()->json(['error' => 'Missing signedPayload'], 400);
        }

        // 1. Verify Signed Payload JWS & Envelope
        $verification = $this->appleService->verifyNotification($signedPayload);

        if (!$verification['success']) {
            Log::warning('Apple Webhook verification failed', [
                'error_code' => $verification['error_code'],
                'error_message' => $verification['error_message'],
            ]);

            // If bundle ID mismatch, return 400 Bad Request
            if (($verification['error_code'] ?? '') === 'bundle_id_mismatch') {
                return response()->json(['error' => 'Bundle ID mismatch'], 400);
            }

            return response()->json(['error' => $verification['error_message']], 422);
        }

        $notificationUUID = $verification['notification_uuid'];
        $eventType = $verification['notification_type'];
        $subtype = $verification['subtype'];
        $environment = $verification['environment'];
        $signedDate = $verification['signed_date'];

        // 2. Delivery Idempotency: Check if notificationUUID already recorded
        $existingEvent = StoreNotificationEvent::where('store', StoreNotificationEvent::STORE_APPLE)
            ->where('external_event_id', $notificationUUID)
            ->first();

        if ($existingEvent) {
            Log::info('Apple Webhook: Duplicate notificationUUID received, acknowledging delivery', [
                'notification_uuid' => $notificationUUID,
                'event_id' => $existingEvent->id,
            ]);
            return response()->json(['status' => 'duplicate_acknowledged', 'event_id' => $existingEvent->id], 200);
        }

        // 3. Persist durable event ledger
        $event = DB::transaction(function () use ($notificationUUID, $eventType, $subtype, $environment, $signedDate, $verification) {
            return StoreNotificationEvent::create([
                'store' => StoreNotificationEvent::STORE_APPLE,
                'environment' => $environment,
                'external_event_id' => $notificationUUID,
                'event_type' => $eventType,
                'event_subtype' => $subtype,
                'event_timestamp' => $signedDate,
                'received_at' => now(),
                'processing_status' => StoreNotificationEvent::STATUS_PENDING,
                'raw_payload' => $verification['raw_payload'],
            ]);
        });

        // 4. Process event (synchronously or queue dispatch)
        if (app()->environment('testing') || config('queue.default') === 'sync') {
            $this->lifecycleService->processAppleEvent($event);
        } else {
            ProcessStoreNotificationJob::dispatch($event->id);
        }

        return response()->json(['status' => 'success', 'event_id' => $event->id], 200);
    }
}

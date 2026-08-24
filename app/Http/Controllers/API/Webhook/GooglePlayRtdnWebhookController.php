<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStoreNotificationJob;
use App\Models\StoreNotificationEvent;
use App\Services\Payment\GooglePlayBillingService;
use App\Services\Payment\StoreSubscriptionLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class GooglePlayRtdnWebhookController extends Controller
{
    public function __construct(
        private readonly GooglePlayBillingService $googleService,
        private readonly StoreSubscriptionLifecycleService $lifecycleService,
    ) {
    }

    /**
     * Handle Google Play Real-Time Developer Notifications (Cloud Pub/Sub Push)
     * POST /api/webhooks/google-play/rtdn
     */
    public function handle(Request $request): JsonResponse
    {
        if (! config('store_billing.enabled', false) || ! config('store_billing.notifications_enabled', false)) {
            Log::info('Google Play RTDN: Store notifications disabled, ignoring payload without mutation.');
            return response()->json(['status' => 'disabled', 'message' => 'Google Play RTDN notifications are currently disabled.'], 200);
        }

        $authHeader = $request->header('Authorization');
        $body = $request->all();

        // 1. Authenticate Pub/Sub Push & Decode Envelope
        $verification = $this->googleService->verifyPubSubPush($body, $authHeader);

        if (!$verification['success']) {
            Log::warning('Google Play RTDN Webhook verification failed', [
                'error_code' => $verification['error_code'] ?? 'unknown',
                'error_message' => $verification['error_message'] ?? 'Verification failed',
            ]);

            if (($verification['error_code'] ?? '') === 'unauthorized_pubsub_push') {
                return response()->json(['error' => 'Unauthorized Pub/Sub Push'], 401);
            }

            if (($verification['error_code'] ?? '') === 'package_name_mismatch') {
                return response()->json(['error' => 'Package name mismatch'], 400);
            }

            return response()->json(['error' => $verification['error_message'] ?? 'Bad Request'], 400);
        }

        $messageId = $verification['message_id'];
        $eventType = $verification['event_type'];
        $eventSubtype = $verification['event_subtype'];
        $eventTime = $verification['event_time'];

        // 2. Delivery Idempotency: Deduplicate on messageId
        $existingEvent = StoreNotificationEvent::where('store', StoreNotificationEvent::STORE_GOOGLE)
            ->where('external_event_id', $messageId)
            ->first();

        if ($existingEvent) {
            Log::info('Google Play RTDN: Duplicate messageId received, acknowledging delivery', [
                'message_id' => $messageId,
                'event_id' => $existingEvent->id,
            ]);
            return response()->json(['status' => 'duplicate_acknowledged', 'event_id' => $existingEvent->id], 200);
        }

        // 3. Persist durable event ledger
        $event = DB::transaction(function () use ($messageId, $eventType, $eventSubtype, $eventTime, $verification) {
            return StoreNotificationEvent::create([
                'store' => StoreNotificationEvent::STORE_GOOGLE,
                'environment' => config('store_billing.google.environment', 'sandbox'),
                'external_event_id' => $messageId,
                'event_type' => $eventType,
                'event_subtype' => $eventSubtype,
                'event_timestamp' => $eventTime,
                'received_at' => now(),
                'processing_status' => StoreNotificationEvent::STATUS_PENDING,
                'raw_payload' => $verification['raw_payload'],
            ]);
        });

        // 4. Process event (synchronously in test or queue dispatch)
        if (app()->environment('testing') || config('queue.default') === 'sync') {
            $this->lifecycleService->processGoogleEvent($event);
        } else {
            ProcessStoreNotificationJob::dispatch($event->id);
        }

        return response()->json(['status' => 'success', 'event_id' => $event->id], 200);
    }
}

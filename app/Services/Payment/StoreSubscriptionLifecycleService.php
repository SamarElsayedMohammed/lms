<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\StoreNotificationEvent;
use App\Models\StoreTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Payment\DTO\StorePurchaseResult;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class StoreSubscriptionLifecycleService
{
    public function __construct(
        private readonly AppleStoreBillingService $appleService,
        private readonly GooglePlayBillingService $googleService,
        private readonly StoreBillingManager $storeBillingManager,
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * Process an Apple App Store Server Notification V2 Event.
     */
    public function processAppleEvent(StoreNotificationEvent $event): bool
    {
        $event->markProcessing();

        try {
            $payload = $event->raw_payload;
            $eventType = $event->event_type;
            $subtype = $event->event_subtype;

            // 1. Handle TEST notifications
            if ($eventType === 'TEST') {
                Log::info('Apple test notification received and recorded.', [
                    'event_id' => $event->id,
                    'external_event_id' => $event->external_event_id,
                ]);
                $event->markProcessed();
                return true;
            }

            // 2. Decode signedTransactionInfo if available
            $txResult = null;
            if (!empty($payload['data']['signedTransactionInfo']) && is_string($payload['data']['signedTransactionInfo'])) {
                $txResult = $this->appleService->verify(['signed_transaction' => $payload['data']['signedTransactionInfo']]);
            }

            // 3. Fallback: Check if signedRenewalInfo exists
            $renewalInfo = null;
            if (!empty($payload['data']['signedRenewalInfo']) && is_string($payload['data']['signedRenewalInfo'])) {
                $renewalDecoded = json_decode(base64_decode(explode('.', $payload['data']['signedRenewalInfo'])[1] ?? ''), true);
                if (is_array($renewalDecoded)) {
                    $renewalInfo = $renewalDecoded;
                }
            }

            // 4. If no transaction result exists, record as ignored/no-action
            if (!$txResult || !$txResult->isVerified) {
                $event->markIgnored('No valid signed transaction info present in notification payload.');
                return true;
            }

            // Populate event index identifiers
            $event->update([
                'store_product_id' => $txResult->storeProductId,
                'transaction_id' => $txResult->transactionId,
                'original_transaction_id' => $txResult->originalTransactionId,
            ]);

            // 5. Reconcile lifecycle event inside atomic DB transaction
            return DB::transaction(function () use ($event, $eventType, $subtype, $txResult, $renewalInfo) {
                // Find existing store transaction and owner user
                $existingTx = StoreTransaction::where('store', StoreTransaction::STORE_APPLE)
                    ->where(function ($q) use ($txResult) {
                        $q->where('original_transaction_id', $txResult->originalTransactionId)
                            ->orWhere('transaction_id', $txResult->transactionId);
                    })
                    ->lockForUpdate()
                    ->first();

                $user = $existingTx ? $existingTx->user : null;

                // If user not found yet and this is an initial purchase or resubscribe
                if (!$user && in_array($eventType, ['SUBSCRIBED', 'DID_RENEW', 'OFFER_REDEEMED'], true)) {
                    // Try resolving by appAccountToken or link to user if exists
                    Log::warning('Apple notification: unlinked user for initial transaction', [
                        'original_transaction_id' => $txResult->originalTransactionId,
                        'event_type' => $eventType,
                    ]);
                    $event->markIgnored('No existing Skillso user linked to original_transaction_id.');
                    return true;
                }

                if (!$user) {
                    $event->markIgnored('No matching Skillso user found for store transaction.');
                    return true;
                }

                // Resolve canonical subscription
                $subscription = $existingTx?->subscription
                    ?? Subscription::where('user_id', $user->id)
                        ->where('store_provider', 'app_store')
                        ->where('store_original_transaction_id', $txResult->originalTransactionId)
                        ->lockForUpdate()
                        ->first()
                    ?? $this->subscriptionService->getActiveSubscription($user);

                // Handle Specific Event Types
                switch ($eventType) {
                    case 'SUBSCRIBED':
                    case 'DID_RENEW':
                    case 'OFFER_REDEEMED':
                    case 'RENEWAL_EXTENDED':
                        $this->handleAppleRenewal($user, $subscription, $txResult, $eventType, $subtype, $event);
                        break;

                    case 'DID_CHANGE_RENEWAL_STATUS':
                        $this->handleAppleRenewalStatusChange($subscription, $subtype, $renewalInfo, $event);
                        break;

                    case 'DID_FAIL_TO_RENEW':
                        $this->handleAppleFailedRenewal($subscription, $subtype, $renewalInfo, $event);
                        break;

                    case 'GRACE_PERIOD_EXPIRED':
                        $this->handleAppleGracePeriodExpired($subscription, $event);
                        break;

                    case 'EXPIRED':
                        $this->handleAppleExpired($subscription, $txResult, $event);
                        break;

                    case 'REFUND':
                        $this->handleAppleRefund($user, $subscription, $txResult, $event);
                        break;

                    case 'REFUND_REVERSED':
                        $this->handleAppleRefundReversed($user, $subscription, $txResult, $event);
                        break;

                    case 'REVOKE':
                        $this->handleAppleRevoke($user, $subscription, $txResult, $event);
                        break;

                    case 'DID_CHANGE_RENEWAL_PREF':
                        Log::info('Apple renewal pref changed (plan upgrade/downgrade scheduled)', [
                            'user_id' => $user->id,
                            'subtype' => $subtype,
                            'product_id' => $txResult->storeProductId,
                        ]);
                        $event->markProcessed($user->id, $subscription?->id, $existingTx?->id);
                        break;

                    default:
                        Log::info('Unhandled or future Apple notification type received safely', [
                            'event_type' => $eventType,
                            'subtype' => $subtype,
                        ]);
                        $event->markIgnored("No action required for event type {$eventType}", $user->id);
                        break;
                }

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('Apple notification processing exception', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $event->markFailed('processing_exception', $e->getMessage());
            return false;
        }
    }

    /**
     * Process a Google Play RTDN (Pub/Sub) Event.
     */
    public function processGoogleEvent(StoreNotificationEvent $event): bool
    {
        $event->markProcessing();

        try {
            $payload = $event->raw_payload;
            $eventType = $event->event_type;

            // 1. Handle TEST notifications
            if ($event->event_type === 'TEST' || isset($payload['testNotification'])) {
                Log::info('Google Play test notification received and recorded.', [
                    'event_id' => $event->id,
                    'message_id' => $event->external_event_id,
                ]);
                $event->markProcessed();
                return true;
            }

            // 2. Extract purchase token from DeveloperNotification
            $subNotif = $payload['subscriptionNotification'] ?? null;
            $purchaseToken = (string) ($subNotif['purchaseToken'] ?? $event->raw_payload['purchase_token'] ?? '');

            if ($purchaseToken === '') {
                $event->markIgnored('Missing purchaseToken in Google RTDN notification.');
                return true;
            }

            $tokenHash = StoreTransaction::hashToken($purchaseToken);
            $event->update(['purchase_token_hash' => $tokenHash]);

            // 3. Query Google Play SubscriptionsV2 for Authoritative Truth (Never trust RTDN signal alone!)
            $verificationResult = $this->googleService->verify([
                'purchase_token' => $purchaseToken,
                'product_id' => (string) ($subNotif['subscriptionId'] ?? ''),
            ]);

            if (!$verificationResult->isVerified) {
                Log::warning('Google Play SubscriptionsV2 verification failed during RTDN processing', [
                    'event_id' => $event->id,
                    'error' => $verificationResult->errorMessage,
                ]);
                $event->markFailed('api_verification_failed', $verificationResult->errorMessage ?? 'Failed to verify with Google Play API');
                return false;
            }

            // 4. Reconcile inside atomic DB transaction
            return DB::transaction(function () use ($event, $eventType, $purchaseToken, $tokenHash, $verificationResult) {
                // Find existing store transaction and owner user
                $existingTx = StoreTransaction::where('store', StoreTransaction::STORE_GOOGLE)
                    ->where(function ($q) use ($tokenHash, $verificationResult) {
                        $q->where('purchase_token_hash', $tokenHash);
                        if (!empty($verificationResult->originalTransactionId)) {
                            $q->orWhere('original_transaction_id', $verificationResult->originalTransactionId);
                        }
                    })
                    ->lockForUpdate()
                    ->first();

                $user = $existingTx ? $existingTx->user : null;

                if (!$user) {
                    Log::warning('Google RTDN: No matching Skillso user found for purchase token', [
                        'token_hash' => $tokenHash,
                        'event_type' => $eventType,
                    ]);
                    $event->markIgnored('No existing Skillso user linked to Google purchase token.');
                    return true;
                }

                $subscription = $existingTx?->subscription
                    ?? Subscription::where('user_id', $user->id)
                        ->where('store_provider', 'google_play')
                        ->lockForUpdate()
                        ->first()
                    ?? $this->subscriptionService->getActiveSubscription($user);

                switch ($eventType) {
                    case 'SUBSCRIPTION_PURCHASED':
                    case 'SUBSCRIPTION_RENEWED':
                    case 'SUBSCRIPTION_RECOVERED':
                    case 'SUBSCRIPTION_RESTARTED':
                        $this->handleGoogleRenewal($user, $subscription, $verificationResult, $eventType, $event);
                        break;

                    case 'SUBSCRIPTION_CANCELED':
                        $this->handleGoogleCancellation($subscription, $verificationResult, $event);
                        break;

                    case 'SUBSCRIPTION_IN_GRACE_PERIOD':
                        $this->handleGoogleGracePeriod($subscription, $verificationResult, $event);
                        break;

                    case 'SUBSCRIPTION_ON_HOLD':
                        $this->handleGoogleOnHold($subscription, $verificationResult, $event);
                        break;

                    case 'SUBSCRIPTION_PAUSED':
                        $this->handleGooglePaused($subscription, $verificationResult, $event);
                        break;

                    case 'SUBSCRIPTION_EXPIRED':
                        $this->handleGoogleExpired($subscription, $verificationResult, $event);
                        break;

                    case 'SUBSCRIPTION_REVOKED':
                        $this->handleGoogleRevoked($user, $subscription, $verificationResult, $event);
                        break;

                    default:
                        Log::info('Google RTDN notification processed generically', [
                            'event_type' => $eventType,
                            'user_id' => $user->id,
                        ]);
                        $event->markProcessed($user->id, $subscription?->id, $existingTx?->id);
                        break;
                }

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('Google RTDN processing exception', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $event->markFailed('processing_exception', $e->getMessage());
            return false;
        }
    }

    // ==========================================
    // APPLE SPECIFIC LIFECYCLE HANDLERS
    // ==========================================

    private function handleAppleRenewal(
        User $user,
        ?Subscription $subscription,
        StorePurchaseResult $txResult,
        string $eventType,
        ?string $subtype,
        StoreNotificationEvent $event
    ): void {
        $plan = $this->storeBillingManager->resolvePlan(StoreTransaction::STORE_APPLE, $txResult->storeProductId);
        if (!$plan) {
            $event->markFailed('unknown_product', "No plan mapped to {$txResult->storeProductId}");
            return;
        }

        // Stale event protection: If subscription already exists and ends_at is AFTER this event's expiresAt
        if ($subscription && $subscription->ends_at && $txResult->expiresAt && $subscription->ends_at->greaterThan($txResult->expiresAt)) {
            Log::info('Stale Apple renewal event detected (current ends_at is newer). Skipping date regression.', [
                'subscription_id' => $subscription->id,
                'current_ends_at' => $subscription->ends_at->toIso8601String(),
                'event_expires_at' => $txResult->expiresAt->toIso8601String(),
            ]);
            $event->markProcessed($user->id, $subscription->id);
            return;
        }

        // Check if exact transaction_id already recorded
        $storeTx = StoreTransaction::where('store', StoreTransaction::STORE_APPLE)
            ->where('transaction_id', $txResult->transactionId)
            ->first();

        if (!$storeTx) {
            $storeTx = StoreTransaction::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'store' => StoreTransaction::STORE_APPLE,
                'environment' => $txResult->environment,
                'store_product_id' => $txResult->storeProductId,
                'transaction_id' => $txResult->transactionId,
                'original_transaction_id' => $txResult->originalTransactionId,
                'purchase_token' => $txResult->purchaseToken,
                'purchase_token_hash' => StoreTransaction::hashToken($txResult->purchaseToken),
                'status' => 'active',
                'purchased_at' => $txResult->purchasedAt,
                'expires_at' => $txResult->expiresAt,
                'auto_renew' => true,
                'is_verified' => true,
                'amount' => $txResult->amount ?? (float) ($plan->usd_price ?? $plan->price),
                'currency' => $txResult->currency ?? ($plan->usd_price ? 'USD' : 'EGP'),
                'raw_payload' => $txResult->rawPayload,
            ]);
        }

        // Activate or extend canonical subscription
        $activeSub = $this->subscriptionService->activateVerifiedStoreSubscription(
            $user,
            $plan,
            $txResult,
            $storeTx
        );

        $event->markProcessed($user->id, $activeSub->id, $storeTx->id);

        Log::info('Apple subscription successfully renewed / reconciled via Server Notification', [
            'user_id' => $user->id,
            'subscription_id' => $activeSub->id,
            'transaction_id' => $txResult->transactionId,
            'expires_at' => $activeSub->ends_at?->toIso8601String(),
            'event_type' => $eventType,
        ]);
    }

    private function handleAppleRenewalStatusChange(
        ?Subscription $subscription,
        ?string $subtype,
        ?array $renewalInfo,
        StoreNotificationEvent $event
    ): void {
        if (!$subscription) {
            $event->markIgnored('No active subscription to update renewal status.');
            return;
        }

        $autoRenewDisabled = ($subtype === 'AUTO_RENEW_DISABLED')
            || (isset($renewalInfo['autoRenewStatus']) && (int) $renewalInfo['autoRenewStatus'] === 0);

        if ($autoRenewDisabled) {
            // Cancellation of future renewal: auto_renew = false, access continues until ends_at!
            $subscription->update(['auto_renew' => false]);
            Log::info('Apple auto_renew disabled by user. Entitlement access preserved until ends_at.', [
                'subscription_id' => $subscription->id,
                'ends_at' => $subscription->ends_at?->toIso8601String(),
            ]);
        } else {
            // Auto renew re-enabled
            $subscription->update(['auto_renew' => true]);
            Log::info('Apple auto_renew re-enabled by user.', [
                'subscription_id' => $subscription->id,
            ]);
        }

        $event->markProcessed($subscription->user_id, $subscription->id);
    }

    private function handleAppleFailedRenewal(
        ?Subscription $subscription,
        ?string $subtype,
        ?array $renewalInfo,
        StoreNotificationEvent $event
    ): void {
        if (!$subscription) {
            $event->markIgnored('No subscription found for failed renewal event.');
            return;
        }

        if ($subtype === 'GRACE_PERIOD') {
            // Retain entitlement during verified grace period!
            Log::info('Apple subscription entered billing grace period. Access maintained.', [
                'subscription_id' => $subscription->id,
            ]);
            // Update store transactions status
            StoreTransaction::where('subscription_id', $subscription->id)
                ->update(['status' => 'in_grace_period']);
        } else {
            // In billing retry without grace period
            Log::warning('Apple subscription failed to renew (billing retry period).', [
                'subscription_id' => $subscription->id,
            ]);
        }

        $event->markProcessed($subscription->user_id, $subscription->id);
    }

    private function handleAppleGracePeriodExpired(?Subscription $subscription, StoreNotificationEvent $event): void
    {
        if (!$subscription) {
            $event->markIgnored('No subscription found for grace period expired event.');
            return;
        }

        // Grace period expired: if ends_at is past, mark subscription as expired
        if ($subscription->ends_at && $subscription->ends_at->isPast()) {
            $subscription->update([
                'status' => Subscription::STATUS_EXPIRED,
                'auto_renew' => false,
            ]);

            StoreTransaction::where('subscription_id', $subscription->id)
                ->update(['status' => 'expired']);

            Log::info('Apple grace period expired: marked canonical subscription as expired.', [
                'subscription_id' => $subscription->id,
            ]);
        }

        $event->markProcessed($subscription->user_id, $subscription->id);
    }

    private function handleAppleExpired(?Subscription $subscription, StorePurchaseResult $txResult, StoreNotificationEvent $event): void
    {
        if (!$subscription) {
            $event->markIgnored('No subscription found for expired event.');
            return;
        }

        // Stale event protection: Check if a newer valid transaction exists
        $hasNewerValidTx = StoreTransaction::where('original_transaction_id', $txResult->originalTransactionId)
            ->where('store', StoreTransaction::STORE_APPLE)
            ->where('expires_at', '>', now())
            ->where('is_revoked', false)
            ->exists();

        if ($hasNewerValidTx) {
            Log::warning('Ignoring stale Apple EXPIRED notification; newer active transaction exists.', [
                'subscription_id' => $subscription->id,
                'original_transaction_id' => $txResult->originalTransactionId,
            ]);
            $event->markProcessed($subscription->user_id, $subscription->id);
            return;
        }

        $subscription->update([
            'status' => Subscription::STATUS_EXPIRED,
            'auto_renew' => false,
        ]);

        StoreTransaction::where('transaction_id', $txResult->transactionId)
            ->update(['status' => 'expired']);

        Log::info('Apple subscription marked as expired via server notification.', [
            'subscription_id' => $subscription->id,
        ]);

        $event->markProcessed($subscription->user_id, $subscription->id);
    }

    private function handleAppleRefund(User $user, ?Subscription $subscription, StorePurchaseResult $txResult, StoreNotificationEvent $event): void
    {
        // Mark specific transaction as refunded
        $refundedTx = StoreTransaction::where('store', StoreTransaction::STORE_APPLE)
            ->where('transaction_id', $txResult->transactionId)
            ->first();

        if ($refundedTx) {
            $refundedTx->update([
                'status' => 'refunded',
                'is_refunded' => true,
                'is_revoked' => true,
            ]);
        }

        // Check if refunded transaction is the CURRENT active period
        if ($subscription && $subscription->is_active) {
            $latestActiveTx = StoreTransaction::where('subscription_id', $subscription->id)
                ->where('is_refunded', false)
                ->where('is_revoked', false)
                ->where('expires_at', '>', now())
                ->orderByDesc('expires_at')
                ->first();

            // If no other active un-refunded transactions remain, revoke subscription access
            if (!$latestActiveTx) {
                $subscription->update([
                    'status' => Subscription::STATUS_CANCELLED,
                    'cancellation_reason' => 'Apple In-App Purchase refunded by store.',
                    'cancelled_at' => now(),
                    'auto_renew' => false,
                ]);

                Log::warning('Apple purchase refund: revoked active subscription entitlement', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'transaction_id' => $txResult->transactionId,
                ]);
            } else {
                Log::info('Apple purchase refund on historical transaction; active subscription remains entitled through newer renewal.', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'refunded_transaction_id' => $txResult->transactionId,
                    'active_transaction_id' => $latestActiveTx->transactionId,
                ]);
            }
        }

        $event->markProcessed($user->id, $subscription?->id, $refundedTx?->id);
    }

    private function handleAppleRefundReversed(User $user, ?Subscription $subscription, StorePurchaseResult $txResult, StoreNotificationEvent $event): void
    {
        $storeTx = StoreTransaction::where('store', StoreTransaction::STORE_APPLE)
            ->where('transaction_id', $txResult->transactionId)
            ->first();

        if ($storeTx) {
            $storeTx->update([
                'status' => 'active',
                'is_refunded' => false,
                'is_revoked' => false,
            ]);
        }

        if ($subscription && $storeTx && $storeTx->expires_at && $storeTx->expires_at->isFuture()) {
            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'ends_at' => $storeTx->expires_at,
            ]);
            Log::info('Apple refund reversed: restored canonical subscription access.', [
                'subscription_id' => $subscription->id,
            ]);
        }

        $event->markProcessed($user->id, $subscription?->id, $storeTx?->id);
    }

    private function handleAppleRevoke(User $user, ?Subscription $subscription, StorePurchaseResult $txResult, StoreNotificationEvent $event): void
    {
        $storeTx = StoreTransaction::where('store', StoreTransaction::STORE_APPLE)
            ->where('transaction_id', $txResult->transactionId)
            ->first();

        if ($storeTx) {
            $storeTx->update([
                'status' => 'revoked',
                'is_revoked' => true,
            ]);
        }

        if ($subscription) {
            $subscription->update([
                'status' => Subscription::STATUS_CANCELLED,
                'cancellation_reason' => 'StoreKit purchase revoked by Apple.',
                'cancelled_at' => now(),
                'auto_renew' => false,
            ]);

            Log::warning('Apple purchase revoked: canceled canonical subscription access immediately.', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'transaction_id' => $txResult->transactionId,
            ]);
        }

        $event->markProcessed($user->id, $subscription?->id, $storeTx?->id);
    }

    // ==========================================
    // GOOGLE SPECIFIC LIFECYCLE HANDLERS
    // ==========================================

    private function handleGoogleRenewal(
        User $user,
        ?Subscription $subscription,
        StorePurchaseResult $verificationResult,
        string $eventType,
        StoreNotificationEvent $event
    ): void {
        $plan = $this->storeBillingManager->resolvePlan(StoreTransaction::STORE_GOOGLE, $verificationResult->storeProductId);
        if (!$plan) {
            $event->markFailed('unknown_product', "No plan mapped to Google product {$verificationResult->storeProductId}");
            return;
        }

        // Stale event protection
        if ($subscription && $subscription->ends_at && $verificationResult->expiresAt && $subscription->ends_at->greaterThan($verificationResult->expiresAt)) {
            Log::info('Stale Google renewal event detected (current ends_at is newer). Skipping date regression.', [
                'subscription_id' => $subscription->id,
                'current_ends_at' => $subscription->ends_at->toIso8601String(),
                'google_expires_at' => $verificationResult->expiresAt->toIso8601String(),
            ]);
            $event->markProcessed($user->id, $subscription->id);
            return;
        }

        $tokenHash = StoreTransaction::hashToken($verificationResult->purchaseToken);

        // Check if exact transaction_id already recorded
        $storeTx = StoreTransaction::where('store', StoreTransaction::STORE_GOOGLE)
            ->where('transaction_id', $verificationResult->transactionId)
            ->first();

        if (!$storeTx) {
            $storeTx = StoreTransaction::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'store' => StoreTransaction::STORE_GOOGLE,
                'environment' => $verificationResult->environment,
                'store_product_id' => $verificationResult->storeProductId,
                'transaction_id' => $verificationResult->transactionId,
                'original_transaction_id' => $verificationResult->originalTransactionId,
                'purchase_token' => $verificationResult->purchaseToken,
                'purchase_token_hash' => $tokenHash,
                'status' => 'active',
                'purchased_at' => $verificationResult->purchasedAt,
                'expires_at' => $verificationResult->expiresAt,
                'auto_renew' => $verificationResult->autoRenew,
                'is_verified' => true,
                'amount' => (float) ($plan->usd_price ?? $plan->price),
                'currency' => $plan->usd_price ? 'USD' : 'EGP',
                'raw_payload' => $verificationResult->rawPayload,
            ]);
        }

        // Activate or extend canonical subscription
        $activeSub = $this->subscriptionService->activateVerifiedStoreSubscription(
            $user,
            $plan,
            $verificationResult,
            $storeTx
        );

        $event->markProcessed($user->id, $activeSub->id, $storeTx->id);

        Log::info('Google subscription renewed / reconciled via RTDN', [
            'user_id' => $user->id,
            'subscription_id' => $activeSub->id,
            'transaction_id' => $verificationResult->transactionId,
            'expires_at' => $activeSub->ends_at?->toIso8601String(),
            'event_type' => $eventType,
        ]);
    }

    private function handleGoogleCancellation(
        ?Subscription $subscription,
        StorePurchaseResult $verificationResult,
        StoreNotificationEvent $event
    ): void {
        if (!$subscription) {
            $event->markIgnored('No active subscription to cancel.');
            return;
        }

        // User disabled auto-renew: auto_renew = false, but access continues until ends_at!
        $subscription->update(['auto_renew' => false]);

        StoreTransaction::where('subscription_id', $subscription->id)
            ->update(['auto_renew' => false]);

        Log::info('Google Play subscription auto_renew canceled by user. Access preserved until ends_at.', [
            'subscription_id' => $subscription->id,
            'ends_at' => $subscription->ends_at?->toIso8601String(),
        ]);

        $event->markProcessed($subscription->user_id, $subscription->id);
    }

    private function handleGoogleGracePeriod(
        ?Subscription $subscription,
        StorePurchaseResult $verificationResult,
        StoreNotificationEvent $event
    ): void {
        if (!$subscription) {
            $event->markIgnored('No subscription found for grace period.');
            return;
        }

        // In grace period: access retained!
        StoreTransaction::where('subscription_id', $subscription->id)
            ->update(['status' => 'in_grace_period']);

        Log::info('Google Play subscription entered grace period. Access maintained.', [
            'subscription_id' => $subscription->id,
        ]);

        $event->markProcessed($subscription->user_id, $subscription->id);
    }

    private function handleGoogleOnHold(
        ?Subscription $subscription,
        StorePurchaseResult $verificationResult,
        StoreNotificationEvent $event
    ): void {
        if (!$subscription) {
            $event->markIgnored('No subscription found for on_hold.');
            return;
        }

        // Account on hold: provider says no entitlement -> suspend Skillso access
        $subscription->update(['status' => Subscription::STATUS_EXPIRED]);

        StoreTransaction::where('subscription_id', $subscription->id)
            ->update(['status' => 'on_hold']);

        Log::warning('Google Play subscription placed on hold. Premium access suspended.', [
            'subscription_id' => $subscription->id,
        ]);

        $event->markProcessed($subscription->user_id, $subscription->id);
    }

    private function handleGooglePaused(
        ?Subscription $subscription,
        StorePurchaseResult $verificationResult,
        StoreNotificationEvent $event
    ): void {
        if (!$subscription) {
            $event->markIgnored('No subscription found for pause.');
            return;
        }

        StoreTransaction::where('subscription_id', $subscription->id)
            ->update(['status' => 'paused']);

        $event->markProcessed($subscription->user_id, $subscription->id);
    }

    private function handleGoogleExpired(
        ?Subscription $subscription,
        StorePurchaseResult $verificationResult,
        StoreNotificationEvent $event
    ): void {
        if (!$subscription) {
            $event->markIgnored('No subscription found for expired event.');
            return;
        }

        // Stale event protection
        if ($verificationResult->expiresAt && $verificationResult->expiresAt->isFuture()) {
            Log::warning('Ignoring stale Google EXPIRED event; SubscriptionsV2 reports active future expiry.', [
                'subscription_id' => $subscription->id,
                'expires_at' => $verificationResult->expiresAt->toIso8601String(),
            ]);
            $event->markProcessed($subscription->user_id, $subscription->id);
            return;
        }

        $subscription->update([
            'status' => Subscription::STATUS_EXPIRED,
            'auto_renew' => false,
        ]);

        StoreTransaction::where('subscription_id', $subscription->id)
            ->update(['status' => 'expired']);

        Log::info('Google Play subscription marked as expired via RTDN.', [
            'subscription_id' => $subscription->id,
        ]);

        $event->markProcessed($subscription->user_id, $subscription->id);
    }

    private function handleGoogleRevoked(
        User $user,
        ?Subscription $subscription,
        StorePurchaseResult $verificationResult,
        StoreNotificationEvent $event
    ): void {
        StoreTransaction::where('store', StoreTransaction::STORE_GOOGLE)
            ->where('purchase_token_hash', StoreTransaction::hashToken($verificationResult->purchaseToken))
            ->update([
                'status' => 'revoked',
                'is_revoked' => true,
            ]);

        if ($subscription) {
            $subscription->update([
                'status' => Subscription::STATUS_CANCELLED,
                'cancellation_reason' => 'Google Play purchase revoked by store.',
                'cancelled_at' => now(),
                'auto_renew' => false,
            ]);

            Log::warning('Google Play purchase revoked: canceled canonical subscription access immediately.', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
            ]);
        }

        $event->markProcessed($user->id, $subscription?->id);
    }
}

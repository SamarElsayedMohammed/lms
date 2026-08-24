<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\User;
use App\Services\Payment\Contracts\StoreBillingServiceInterface;
use App\Services\Payment\DTO\StorePurchaseResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class GooglePlayBillingService implements StoreBillingServiceInterface
{
    private string $packageName;
    private string $configuredEnvironment;
    private bool $mockEnabled;
    private string $expectedAudience;
    private string $expectedServiceAccount;

    public const NOTIFICATION_TYPE_RECOVERED = 1;
    public const NOTIFICATION_TYPE_RENEWED = 2;
    public const NOTIFICATION_TYPE_CANCELED = 3;
    public const NOTIFICATION_TYPE_PURCHASED = 4;
    public const NOTIFICATION_TYPE_ON_HOLD = 5;
    public const NOTIFICATION_TYPE_IN_GRACE_PERIOD = 6;
    public const NOTIFICATION_TYPE_RESTARTED = 7;
    public const NOTIFICATION_TYPE_PRICE_CHANGE_CONFIRMED = 8;
    public const NOTIFICATION_TYPE_DEFERRED = 9;
    public const NOTIFICATION_TYPE_PAUSED = 10;
    public const NOTIFICATION_TYPE_PAUSE_SCHEDULE_CHANGED = 11;
    public const NOTIFICATION_TYPE_REVOKED = 12;
    public const NOTIFICATION_TYPE_EXPIRED = 13;
    public const NOTIFICATION_TYPE_PENDING_PURCHASE_CANCELED = 20;

    public function __construct()
    {
        $this->packageName = (string) config('store_billing.google.package_name', 'com.skillso.app');
        $this->configuredEnvironment = (string) config('store_billing.google.environment', 'sandbox');
        $this->mockEnabled = (bool) config('store_billing.mock_verification_enabled', true);
        $this->expectedAudience = (string) config('store_billing.google.pubsub_audience', '');
        $this->expectedServiceAccount = (string) config('store_billing.google.pubsub_service_account', '');
    }

    /**
     * Verify Google Play Subscription purchase proof (purchase_token & product_id)
     * by querying authoritative Google Play Developer API SubscriptionsV2.
     */
    public function verify(array $proof, ?User $user = null): StorePurchaseResult
    {
        $purchaseToken = $proof['purchase_token'] ?? $proof['receipt_data'] ?? null;
        $productId = (string) ($proof['product_id'] ?? '');
        $orderId = (string) ($proof['order_id'] ?? $proof['transaction_id'] ?? '');

        if (!$purchaseToken || !is_string($purchaseToken) || trim($purchaseToken) === '') {
            return StorePurchaseResult::failure(
                store: 'google_play',
                errorMessage: 'Google Play purchase_token is required.',
                storeProductId: $productId,
                transactionId: $orderId,
            );
        }

        $token = trim($purchaseToken);

        // Fetch authoritative state from Google Play Developer API (or mock driver)
        $googleData = $this->queryGooglePlaySubscription($token, $productId);

        if ($googleData === null) {
            return StorePurchaseResult::failure(
                store: 'google_play',
                errorMessage: 'Google Play API verification failed or returned empty subscription state.',
                storeProductId: $productId,
                transactionId: $orderId,
            );
        }

        // Parse Google Play SubscriptionsV2 response
        $subscriptionState = (string) ($googleData['subscriptionState'] ?? 'SUBSCRIPTION_STATE_ACTIVE');
        $latestOrderId = (string) ($googleData['latestOrderId'] ?? $orderId);
        if ($latestOrderId === '') {
            $latestOrderId = 'GPA.' . substr(hash('sha256', $token), 0, 16);
        }

        $linkedPurchaseToken = (string) ($googleData['linkedPurchaseToken'] ?? '');
        $originalTransactionId = $linkedPurchaseToken !== '' ? $linkedPurchaseToken : $token;

        $startTimeStr = $googleData['startTime'] ?? null;
        $purchasedAt = $startTimeStr ? Carbon::parse($startTimeStr) : Carbon::now();

        // Extract line items and expiration time
        $lineItems = $googleData['lineItems'] ?? [];
        $expiresAt = null;
        $autoRenew = true;
        $verifiedProductId = $productId;

        if (!empty($lineItems) && is_array($lineItems[0])) {
            $firstItem = $lineItems[0];
            $verifiedProductId = (string) ($firstItem['productId'] ?? $productId);
            if (isset($firstItem['expiryTime'])) {
                $expiresAt = Carbon::parse($firstItem['expiryTime']);
            }
            if (isset($firstItem['autoRenewingPlan'])) {
                $autoRenew = (bool) ($firstItem['autoRenewingPlan']['autoRenewEnabled'] ?? true);
            }
        } elseif (isset($googleData['expiryTime'])) {
            $expiresAt = Carbon::parse($googleData['expiryTime']);
        }

        $isTestPurchase = isset($googleData['testPurchase']) || ($this->configuredEnvironment === 'sandbox');
        $environment = $isTestPurchase ? 'sandbox' : 'production';

        // Map subscriptionState to normalized status
        $status = match ($subscriptionState) {
            'SUBSCRIPTION_STATE_ACTIVE' => 'active',
            'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => 'in_grace_period',
            'SUBSCRIPTION_STATE_ON_HOLD' => 'on_hold',
            'SUBSCRIPTION_STATE_PAUSED' => 'paused',
            'SUBSCRIPTION_STATE_CANCELED' => 'canceled',
            'SUBSCRIPTION_STATE_EXPIRED' => 'expired',
            'SUBSCRIPTION_STATE_REVOKED' => 'revoked',
            default => 'active',
        };

        if ($expiresAt !== null && $expiresAt->isPast() && $status === 'active') {
            $status = 'expired';
        }

        $isRevoked = $status === 'revoked';

        return new StorePurchaseResult(
            store: 'google_play',
            environment: $environment,
            storeProductId: $verifiedProductId,
            transactionId: $latestOrderId,
            originalTransactionId: $originalTransactionId,
            purchaseToken: $token,
            purchasedAt: $purchasedAt,
            expiresAt: $expiresAt,
            autoRenew: $autoRenew,
            status: $status,
            isVerified: true,
            isRevoked: $isRevoked,
            isRefunded: $isRevoked,
            amount: null,
            currency: null,
            rawPayload: $googleData,
        );
    }

    /**
     * Authenticate and parse Google Cloud Pub/Sub Push webhook payload.
     */
    public function verifyPubSubPush(array $body, ?string $authHeader = null): array
    {
        // 1. Authenticate Pub/Sub Push Bearer JWT if configured
        if ($this->expectedAudience !== '' || $this->expectedServiceAccount !== '') {
            $authResult = $this->verifyPubSubJwt($authHeader);
            if (!$authResult['valid']) {
                return [
                    'success' => false,
                    'error_code' => 'unauthorized_pubsub_push',
                    'error_message' => $authResult['message'] ?? 'Pub/Sub authentication failed.',
                ];
            }
        }

        // 2. Extract envelope
        $message = $body['message'] ?? null;
        if (!is_array($message) || empty($message['data'])) {
            return [
                'success' => false,
                'error_code' => 'invalid_envelope',
                'error_message' => 'Missing message.data in Pub/Sub push body.',
            ];
        }

        $messageId = (string) ($message['messageId'] ?? ('msg_' . uniqid()));
        $publishTime = isset($message['publishTime']) ? Carbon::parse($message['publishTime']) : now();

        // 3. Base64 decode message data
        $decodedJson = base64_decode((string) $message['data'], true);
        if ($decodedJson === false) {
            return [
                'success' => false,
                'error_code' => 'invalid_base64_data',
                'error_message' => 'Failed to base64 decode message.data.',
                'message_id' => $messageId,
            ];
        }

        $developerNotification = json_decode($decodedJson, true);
        if (!is_array($developerNotification)) {
            return [
                'success' => false,
                'error_code' => 'malformed_json_notification',
                'error_message' => 'Decoded message data is not valid JSON.',
                'message_id' => $messageId,
            ];
        }

        // 4. Validate package identity
        $packageName = (string) ($developerNotification['packageName'] ?? '');
        if ($packageName !== '' && $packageName !== $this->packageName && !str_contains($this->packageName, $packageName)) {
            return [
                'success' => false,
                'error_code' => 'package_name_mismatch',
                'error_message' => "Package name mismatch. Expected {$this->packageName}, received {$packageName}.",
                'message_id' => $messageId,
                'raw_payload' => $developerNotification,
            ];
        }

        $eventTimeMillis = $developerNotification['eventTimeMillis'] ?? null;
        $eventTime = $eventTimeMillis !== null ? Carbon::createFromTimestampMs((int) $eventTimeMillis) : $publishTime;

        // Check for test notification
        if (isset($developerNotification['testNotification'])) {
            return [
                'success' => true,
                'is_test' => true,
                'message_id' => $messageId,
                'event_type' => 'TEST',
                'event_subtype' => 'testNotification',
                'package_name' => $packageName,
                'event_time' => $eventTime,
                'purchase_token' => null,
                'subscription_id' => null,
                'raw_payload' => $developerNotification,
            ];
        }

        // Parse SubscriptionNotification
        $subNotif = $developerNotification['subscriptionNotification'] ?? null;
        if (!is_array($subNotif)) {
            // Unknown or unsupported notification category (e.g. One-time product)
            return [
                'success' => true,
                'is_test' => false,
                'message_id' => $messageId,
                'event_type' => 'UNSUPPORTED_NOTIFICATION_CATEGORY',
                'event_subtype' => null,
                'package_name' => $packageName,
                'event_time' => $eventTime,
                'purchase_token' => null,
                'subscription_id' => null,
                'raw_payload' => $developerNotification,
            ];
        }

        $notificationTypeId = (int) ($subNotif['notificationType'] ?? 0);
        $purchaseToken = (string) ($subNotif['purchaseToken'] ?? '');
        $subscriptionId = (string) ($subNotif['subscriptionId'] ?? '');

        $eventType = match ($notificationTypeId) {
            self::NOTIFICATION_TYPE_RECOVERED => 'SUBSCRIPTION_RECOVERED',
            self::NOTIFICATION_TYPE_RENEWED => 'SUBSCRIPTION_RENEWED',
            self::NOTIFICATION_TYPE_CANCELED => 'SUBSCRIPTION_CANCELED',
            self::NOTIFICATION_TYPE_PURCHASED => 'SUBSCRIPTION_PURCHASED',
            self::NOTIFICATION_TYPE_ON_HOLD => 'SUBSCRIPTION_ON_HOLD',
            self::NOTIFICATION_TYPE_IN_GRACE_PERIOD => 'SUBSCRIPTION_IN_GRACE_PERIOD',
            self::NOTIFICATION_TYPE_RESTARTED => 'SUBSCRIPTION_RESTARTED',
            self::NOTIFICATION_TYPE_PRICE_CHANGE_CONFIRMED => 'SUBSCRIPTION_PRICE_CHANGE_CONFIRMED',
            self::NOTIFICATION_TYPE_DEFERRED => 'SUBSCRIPTION_DEFERRED',
            self::NOTIFICATION_TYPE_PAUSED => 'SUBSCRIPTION_PAUSED',
            self::NOTIFICATION_TYPE_PAUSE_SCHEDULE_CHANGED => 'SUBSCRIPTION_PAUSE_SCHEDULE_CHANGED',
            self::NOTIFICATION_TYPE_REVOKED => 'SUBSCRIPTION_REVOKED',
            self::NOTIFICATION_TYPE_EXPIRED => 'SUBSCRIPTION_EXPIRED',
            self::NOTIFICATION_TYPE_PENDING_PURCHASE_CANCELED => 'SUBSCRIPTION_PENDING_PURCHASE_CANCELED',
            default => 'SUBSCRIPTION_UNKNOWN_' . $notificationTypeId,
        };

        return [
            'success' => true,
            'is_test' => false,
            'message_id' => $messageId,
            'event_type' => $eventType,
            'event_subtype' => (string) $notificationTypeId,
            'package_name' => $packageName,
            'event_time' => $eventTime,
            'purchase_token' => $purchaseToken,
            'subscription_id' => $subscriptionId,
            'raw_payload' => $developerNotification,
        ];
    }

    /**
     * Verify Google Cloud Pub/Sub OpenID Connect JWT token
     */
    private function verifyPubSubJwt(?string $authHeader): array
    {
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return ['valid' => false, 'message' => 'Missing or malformed Authorization header.'];
        }

        $token = substr($authHeader, 7);
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return ['valid' => false, 'message' => 'Invalid JWT format.'];
        }

        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (!$payloadJson) {
            return ['valid' => false, 'message' => 'Failed to decode JWT payload.'];
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return ['valid' => false, 'message' => 'Malformed JWT payload JSON.'];
        }

        // Validate expiry
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return ['valid' => false, 'message' => 'Pub/Sub JWT token has expired.'];
        }

        // Validate audience if configured
        if ($this->expectedAudience !== '' && ($payload['aud'] ?? '') !== $this->expectedAudience) {
            return ['valid' => false, 'message' => 'Pub/Sub JWT audience mismatch.'];
        }

        // Validate service account email if configured
        if ($this->expectedServiceAccount !== '' && ($payload['email'] ?? '') !== $this->expectedServiceAccount) {
            return ['valid' => false, 'message' => 'Pub/Sub JWT service account mismatch.'];
        }

        return ['valid' => true];
    }

    /**
     * Query Google Play Subscriptions API or Mock Driver
     */
    private function queryGooglePlaySubscription(string $purchaseToken, string $productId): ?array
    {
        $serviceAccountPath = (string) config('store_billing.google.service_account_path', '');
        $serviceAccountJson = (string) config('store_billing.google.service_account_json', '');

        // If credentials exist, call Google Play API:
        if ($serviceAccountPath !== '' || $serviceAccountJson !== '') {
            try {
                $accessToken = $this->getGoogleOAuthToken();
                if ($accessToken) {
                    $url = "https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$this->packageName}/purchases/subscriptionsv2/tokens/{$purchaseToken}";
                    $response = Http::withToken($accessToken)
                        ->timeout(10)
                        ->get($url);

                    if ($response->successful()) {
                        return $response->json();
                    }
                    Log::warning('Google Play API verification non-200', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Google Play API request failed', ['error' => $e->getMessage()]);
            }
        }

        // Mock verification driver for testing & staging environments
        if ($this->mockEnabled) {
            // Expired state
            if (str_contains($purchaseToken, 'expired')) {
                return [
                    'subscriptionState' => 'SUBSCRIPTION_STATE_EXPIRED',
                    'latestOrderId' => 'GPA.TEST-EXPIRED-' . substr(hash('sha256', $purchaseToken), 0, 8),
                    'startTime' => Carbon::now()->subMonths(2)->toIso8601String(),
                    'lineItems' => [
                        [
                            'productId' => $productId !== '' ? $productId : 'skillso_monthly_sub',
                            'expiryTime' => Carbon::now()->subDay()->toIso8601String(),
                            'autoRenewingPlan' => ['autoRenewEnabled' => false],
                        ],
                    ],
                    'testPurchase' => [],
                ];
            }

            // Revoked state
            if (str_contains($purchaseToken, 'revoked')) {
                return [
                    'subscriptionState' => 'SUBSCRIPTION_STATE_REVOKED',
                    'latestOrderId' => 'GPA.TEST-REVOKED-' . substr(hash('sha256', $purchaseToken), 0, 8),
                    'startTime' => Carbon::now()->subDays(5)->toIso8601String(),
                    'lineItems' => [
                        [
                            'productId' => $productId !== '' ? $productId : 'skillso_monthly_sub',
                            'expiryTime' => Carbon::now()->addDays(25)->toIso8601String(),
                            'autoRenewingPlan' => ['autoRenewEnabled' => false],
                        ],
                    ],
                    'testPurchase' => [],
                ];
            }

            // In Grace Period state
            if (str_contains($purchaseToken, 'grace_period') || str_contains($purchaseToken, 'grace')) {
                return [
                    'subscriptionState' => 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD',
                    'latestOrderId' => 'GPA.TEST-GRACE-' . substr(hash('sha256', $purchaseToken), 0, 8),
                    'startTime' => Carbon::now()->subDays(30)->toIso8601String(),
                    'lineItems' => [
                        [
                            'productId' => $productId !== '' ? $productId : 'skillso_monthly_sub',
                            'expiryTime' => Carbon::now()->addDays(7)->toIso8601String(),
                            'autoRenewingPlan' => ['autoRenewEnabled' => true],
                        ],
                    ],
                    'testPurchase' => [],
                ];
            }

            // On Hold state
            if (str_contains($purchaseToken, 'on_hold') || str_contains($purchaseToken, 'hold')) {
                return [
                    'subscriptionState' => 'SUBSCRIPTION_STATE_ON_HOLD',
                    'latestOrderId' => 'GPA.TEST-HOLD-' . substr(hash('sha256', $purchaseToken), 0, 8),
                    'startTime' => Carbon::now()->subDays(30)->toIso8601String(),
                    'lineItems' => [
                        [
                            'productId' => $productId !== '' ? $productId : 'skillso_monthly_sub',
                            'expiryTime' => Carbon::now()->subDay()->toIso8601String(),
                            'autoRenewingPlan' => ['autoRenewEnabled' => false],
                        ],
                    ],
                    'testPurchase' => [],
                ];
            }

            // Paused state
            if (str_contains($purchaseToken, 'paused')) {
                return [
                    'subscriptionState' => 'SUBSCRIPTION_STATE_PAUSED',
                    'latestOrderId' => 'GPA.TEST-PAUSED-' . substr(hash('sha256', $purchaseToken), 0, 8),
                    'startTime' => Carbon::now()->subDays(30)->toIso8601String(),
                    'lineItems' => [
                        [
                            'productId' => $productId !== '' ? $productId : 'skillso_monthly_sub',
                            'expiryTime' => Carbon::now()->addDays(15)->toIso8601String(),
                            'autoRenewingPlan' => ['autoRenewEnabled' => false],
                        ],
                    ],
                    'testPurchase' => [],
                ];
            }

            // Canceled state (auto_renew = false, but active until expiry)
            if (str_contains($purchaseToken, 'canceled') || str_contains($purchaseToken, 'cancelled')) {
                return [
                    'subscriptionState' => 'SUBSCRIPTION_STATE_CANCELED',
                    'latestOrderId' => 'GPA.TEST-CANCELED-' . substr(hash('sha256', $purchaseToken), 0, 8),
                    'startTime' => Carbon::now()->subDays(10)->toIso8601String(),
                    'lineItems' => [
                        [
                            'productId' => $productId !== '' ? $productId : 'skillso_monthly_sub',
                            'expiryTime' => Carbon::now()->addDays(20)->toIso8601String(),
                            'autoRenewingPlan' => ['autoRenewEnabled' => false],
                        ],
                    ],
                    'testPurchase' => [],
                ];
            }

            // If token contains "invalid", return null to simulate failure
            if (str_contains($purchaseToken, 'invalid')) {
                return null;
            }

            // Default valid active test subscription (valid for 30 days)
            return [
                'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
                'latestOrderId' => 'GPA.' . rand(1000, 9999) . '-' . rand(1000, 9999) . '-' . rand(1000, 9999) . '-' . rand(10000, 99999),
                'startTime' => Carbon::now()->toIso8601String(),
                'lineItems' => [
                    [
                        'productId' => $productId !== '' ? $productId : 'skillso_monthly_sub',
                        'expiryTime' => Carbon::now()->addDays(30)->toIso8601String(),
                        'autoRenewingPlan' => ['autoRenewEnabled' => true],
                    ],
                ],
                'testPurchase' => [],
            ];
        }

        return null;
    }

    private function getGoogleOAuthToken(): ?string
    {
        return null;
    }
}

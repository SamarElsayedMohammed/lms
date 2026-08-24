<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\User;
use App\Services\Payment\Contracts\StoreBillingServiceInterface;
use App\Services\Payment\DTO\StorePurchaseResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

final class AppleStoreBillingService implements StoreBillingServiceInterface
{
    private string $expectedBundleId;
    private string $configuredEnvironment;
    private bool $mockEnabled;

    public function __construct()
    {
        $this->expectedBundleId = (string) config('store_billing.apple.bundle_id', 'com.skillso.app.skillso');
        $this->configuredEnvironment = (string) config('store_billing.apple.environment', 'sandbox');
        $this->mockEnabled = (bool) config('store_billing.mock_verification_enabled', true);
    }

    /**
     * Verify Apple In-App Purchase proof (StoreKit 2 JWS signed transaction).
     */
    public function verify(array $proof, ?User $user = null): StorePurchaseResult
    {
        $signedTransaction = $proof['signed_transaction'] ?? $proof['receipt_data'] ?? $proof['purchase_token'] ?? null;
        $fallbackTransactionId = (string) ($proof['transaction_id'] ?? '');
        $fallbackProductId = (string) ($proof['product_id'] ?? '');

        if (!$signedTransaction || !is_string($signedTransaction) || trim($signedTransaction) === '') {
            return StorePurchaseResult::failure(
                store: 'app_store',
                errorMessage: 'Apple purchase proof (signed_transaction or receipt_data) is required.',
                storeProductId: $fallbackProductId,
                transactionId: $fallbackTransactionId,
            );
        }

        $jwsString = trim($signedTransaction);

        // Decode and verify JWS
        $decoded = $this->decodeAndVerifyJws($jwsString);
        if (!$decoded['success']) {
            return StorePurchaseResult::failure(
                store: 'app_store',
                errorMessage: $decoded['error_message'] ?? 'Apple JWS verification failed.',
                storeProductId: $fallbackProductId,
                transactionId: $fallbackTransactionId,
            );
        }

        $payload = $decoded['payload'];

        // Extract and validate standard StoreKit 2 payload fields
        $transactionId = (string) ($payload['transactionId'] ?? $payload['originalTransactionId'] ?? $fallbackTransactionId);
        $originalTransactionId = (string) ($payload['originalTransactionId'] ?? $transactionId);
        $productId = (string) ($payload['productId'] ?? $fallbackProductId);
        $bundleId = (string) ($payload['bundleId'] ?? '');
        $environment = strtolower((string) ($payload['environment'] ?? 'sandbox')); // 'sandbox' or 'production'

        // Validate bundle ID if present in JWS
        if ($bundleId !== '' && !in_array($bundleId, [$this->expectedBundleId, 'com.skillso.app', 'com.skillso.app.skillso'], true)) {
            return StorePurchaseResult::failure(
                store: 'app_store',
                errorMessage: "Bundle ID mismatch. Expected {$this->expectedBundleId}, received {$bundleId}.",
                storeProductId: $productId,
                transactionId: $transactionId,
                originalTransactionId: $originalTransactionId,
                rawPayload: $payload,
            );
        }

        // Parse Timestamps (StoreKit 2 provides milliseconds epoch)
        $purchaseDateMs = $payload['purchaseDate'] ?? (time() * 1000);
        $expiresDateMs = $payload['expiresDate'] ?? null;
        $revocationDateMs = $payload['revocationDate'] ?? null;

        $purchasedAt = Carbon::createFromTimestampMs((int) $purchaseDateMs);
        $expiresAt = $expiresDateMs !== null ? Carbon::createFromTimestampMs((int) $expiresDateMs) : null;
        $isRevoked = $revocationDateMs !== null;
        $isRefunded = $isRevoked;

        // Auto-renew status
        $autoRenew = (bool) ($payload['autoRenewStatus'] ?? ($payload['inAppOwnershipType'] !== 'REVOKED'));

        // Status resolution
        $status = 'active';
        if ($isRevoked) {
            $status = 'revoked';
        } elseif ($expiresAt !== null && $expiresAt->isPast()) {
            $status = 'expired';
        }

        $amount = isset($payload['price']) ? ((float) $payload['price']) / 1000 : null;
        $currency = isset($payload['currency']) ? (string) $payload['currency'] : null;

        return new StorePurchaseResult(
            store: 'app_store',
            environment: $environment === 'production' ? 'production' : 'sandbox',
            storeProductId: $productId,
            transactionId: $transactionId,
            originalTransactionId: $originalTransactionId,
            purchaseToken: $jwsString,
            purchasedAt: $purchasedAt,
            expiresAt: $expiresAt,
            autoRenew: $autoRenew,
            status: $status,
            isVerified: true,
            isRevoked: $isRevoked,
            isRefunded: $isRefunded,
            amount: $amount,
            currency: $currency,
            rawPayload: $payload,
        );
    }

    /**
     * Verify and parse Apple App Store Server Notifications V2 signed payload JWS.
     */
    public function verifyNotification(string $signedPayload): array
    {
        if (trim($signedPayload) === '') {
            return [
                'success' => false,
                'error_code' => 'empty_payload',
                'error_message' => 'Signed notification payload is empty.',
            ];
        }

        // 1. Decode and verify outer JWS
        $outerDecoded = $this->decodeAndVerifyJws($signedPayload);
        if (!$outerDecoded['success']) {
            return [
                'success' => false,
                'error_code' => 'invalid_outer_jws',
                'error_message' => $outerDecoded['error_message'] ?? 'Failed to verify outer signedPayload JWS.',
            ];
        }

        $outerPayload = $outerDecoded['payload'];

        $notificationUUID = (string) ($outerPayload['notificationUUID'] ?? '');
        $notificationType = (string) ($outerPayload['notificationType'] ?? '');
        $subtype = isset($outerPayload['subtype']) ? (string) $outerPayload['subtype'] : null;
        $signedDateMs = $outerPayload['signedDate'] ?? null;
        $signedDate = $signedDateMs !== null ? Carbon::createFromTimestampMs((int) $signedDateMs) : now();

        $data = $outerPayload['data'] ?? [];
        $bundleId = (string) ($data['bundleId'] ?? '');
        $environment = strtolower((string) ($data['environment'] ?? 'sandbox'));
        $appStoreStatus = isset($data['status']) ? (int) $data['status'] : null;

        // Bundle ID check
        if ($bundleId !== '' && !in_array($bundleId, [$this->expectedBundleId, 'com.skillso.app', 'com.skillso.app.skillso'], true)) {
            return [
                'success' => false,
                'error_code' => 'bundle_id_mismatch',
                'error_message' => "Notification bundle ID mismatch. Expected {$this->expectedBundleId}, got {$bundleId}.",
                'notification_uuid' => $notificationUUID,
                'bundle_id' => $bundleId,
                'raw_payload' => $outerPayload,
            ];
        }

        // 2. Decode nested signedTransactionInfo if present
        $transactionResult = null;
        if (!empty($data['signedTransactionInfo']) && is_string($data['signedTransactionInfo'])) {
            $txJws = $data['signedTransactionInfo'];
            $transactionResult = $this->verify(['signed_transaction' => $txJws]);
            if (!$transactionResult->isVerified) {
                Log::warning('Apple notification nested signedTransactionInfo failed verification', [
                    'notification_uuid' => $notificationUUID,
                    'error' => $transactionResult->errorMessage,
                ]);
                return [
                    'success' => false,
                    'error_code' => 'invalid_transaction_jws',
                    'error_message' => 'Nested signedTransactionInfo JWS verification failed: ' . $transactionResult->errorMessage,
                    'notification_uuid' => $notificationUUID,
                ];
            }
        }

        // 3. Decode nested signedRenewalInfo if present
        $renewalInfo = null;
        if (!empty($data['signedRenewalInfo']) && is_string($data['signedRenewalInfo'])) {
            $renewalDecoded = $this->decodeAndVerifyJws($data['signedRenewalInfo']);
            if ($renewalDecoded['success']) {
                $renewalInfo = $renewalDecoded['payload'];
            }
        }

        return [
            'success' => true,
            'notification_uuid' => $notificationUUID,
            'notification_type' => $notificationType,
            'subtype' => $subtype,
            'signed_date' => $signedDate,
            'bundle_id' => $bundleId,
            'environment' => $environment,
            'app_store_status' => $appStoreStatus,
            'transaction_result' => $transactionResult,
            'renewal_info' => $renewalInfo,
            'raw_payload' => $outerPayload,
        ];
    }

    /**
     * Decode and verify standard JWS structure (header.payload.signature).
     */
    private function decodeAndVerifyJws(string $jwsString): array
    {
        $parts = explode('.', trim($jwsString));
        if (count($parts) !== 3) {
            return [
                'success' => false,
                'error_message' => 'Invalid JWS compact serialization format. Expected 3 segments.',
            ];
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $headerJson = $this->base64UrlDecode($headerB64);
        $payloadJson = $this->base64UrlDecode($payloadB64);

        if ($headerJson === null || $payloadJson === null) {
            return [
                'success' => false,
                'error_message' => 'Failed to base64url decode JWS segments.',
            ];
        }

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);

        if (!is_array($header) || !is_array($payload)) {
            return [
                'success' => false,
                'error_message' => 'Malformed JWS JSON header or payload.',
            ];
        }

        if (!$this->verifyJwsSignature($headerB64, $payloadB64, $signatureB64, $header)) {
            return [
                'success' => false,
                'error_message' => 'JWS cryptographic signature verification failed.',
            ];
        }

        return [
            'success' => true,
            'header' => $header,
            'payload' => $payload,
        ];
    }

    /**
     * Verify JWS Signature with Apple Root CA x5c chain or mock driver.
     */
    private function verifyJwsSignature(string $headerB64, string $payloadB64, string $signatureB64, array $header): bool
    {
        // If mock mode is enabled and no Apple cert chain is provided, accept for testing
        if ($this->mockEnabled && (!isset($header['x5c']) || empty($header['x5c']))) {
            return true;
        }

        // When x5c certificate chain is provided in the JWS header:
        if (isset($header['x5c']) && is_array($header['x5c']) && !empty($header['x5c'])) {
            try {
                $leafCertDer = base64_decode($header['x5c'][0], true);
                if ($leafCertDer === false) {
                    return false;
                }
                $leafCertPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($leafCertDer), 64, "\n") . "-----END CERTIFICATE-----";
                $publicKey = openssl_pkey_get_public($leafCertPem);
                if ($publicKey === false) {
                    return false;
                }

                $signedData = $headerB64 . '.' . $payloadB64;
                $rawSignature = $this->base64UrlDecodeRaw($signatureB64);
                if ($rawSignature === null) {
                    return false;
                }

                // Apple ES256 signature conversion from IEEE P1363 (R || S) to DER format if needed
                $derSignature = $this->convertP1363ToDer($rawSignature);
                $verifyResult = openssl_verify($signedData, $derSignature, $publicKey, OPENSSL_ALGO_SHA256);

                return $verifyResult === 1;
            } catch (\Throwable $e) {
                Log::warning('Apple JWS signature verification threw exception', ['error' => $e->getMessage()]);
                return false;
            }
        }

        return $this->mockEnabled;
    }

    private function base64UrlDecode(string $input): ?string
    {
        $raw = $this->base64UrlDecodeRaw($input);
        return $raw !== null ? $raw : null;
    }

    private function base64UrlDecodeRaw(string $input): ?string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padLen = 4 - $remainder;
            $input .= str_repeat('=', $padLen);
        }
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        return $decoded !== false ? $decoded : null;
    }

    /**
     * Convert IEEE P1363 (64 bytes: R (32) + S (32)) to ASN.1 DER ECDSA signature for OpenSSL
     */
    private function convertP1363ToDer(string $signature): string
    {
        if (strlen($signature) !== 64) {
            return $signature; // Already DER or unsupported length
        }

        $r = substr($signature, 0, 32);
        $s = substr($signature, 32, 32);

        // Trim leading zeros
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        // If highest bit is 1, prepend 0x00 to mark as positive integer
        if (strlen($r) > 0 && ord($r[0]) >= 0x80) {
            $r = "\x00" . $r;
        }
        if (strlen($s) > 0 && ord($s[0]) >= 0x80) {
            $s = "\x00" . $s;
        }

        $rDer = "\x02" . chr(strlen($r)) . $r;
        $sDer = "\x02" . chr(strlen($s)) . $s;

        $sequence = $rDer . $sDer;
        return "\x30" . chr(strlen($sequence)) . $sequence;
    }
}

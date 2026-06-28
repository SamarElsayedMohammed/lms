<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\CachingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kashier payment gateway integration for subscription checkout.
 * Supports multi-currency checkout when a resolved currency is passed in.
 *
 * @see https://developers.kashier.io/
 */
final class KashierCheckoutService implements PaymentGatewayContract
{
    /**
     * Kashier is for subscription checkout. For Order-based flows, use createCheckoutSession with plan/user/amount.
     */
    public function initiate(Order $order, array $options = []): array
    {
        throw new \BadMethodCallException('Kashier is for subscription checkout. Use createCheckoutSession(SubscriptionPlan, User, float) instead.');
    }

    private const BASE_URL_TEST = 'https://checkout.kashier.io';
    private const BASE_URL_LIVE = 'https://checkout.kashier.io';

    /**
     * Create checkout session for subscription payment.
     * Returns URL and metadata for redirect or iframe.
     *
     * @return array{url: string, order_id: string, hash: string, amount: float, currency: string, merchant_id: string, mode: string}
     */
    public function createCheckoutSession(
        SubscriptionPlan $plan,
        User $user,
        float $amount,
        string $currency = 'EGP',
    ): array {
        $config = $this->getConfig();
        $this->validateConfig($config);

        $orderId = 'sub_' . $plan->id . '_' . $user->id . '_' . time();
        $currency = strtoupper($currency);
        $amountFormatted = number_format((float) $amount, 2, '.', '');

        $hash = $this->generateOrderHash(
            $config['merchant_id'],
            $orderId,
            $amountFormatted,
            $currency,
            $config['api_key']
        );

        $baseUrl = $config['mode'] === 'live' ? self::BASE_URL_LIVE : self::BASE_URL_TEST;
        $callbackUrl = urlencode($this->buildCallbackUrl($orderId));

        $url = $baseUrl
            . '?merchantId=' . $config['merchant_id']
            . '&orderId=' . $orderId
            . '&mode=' . $config['mode']
            . '&amount=' . $amountFormatted
            . '&currency=' . $currency
            . '&hash=' . $hash
            . '&merchantRedirect=' . $callbackUrl
            . '&serverWebhook=' . $callbackUrl
            . '&allowedMethods=card,wallet,bank'
            . '&display=en'
            . '&saveCard=false'
            . '&customerReference=usr_' . $user->id;

        $this->kashierLog('Kashier checkout session created', [
            'type' => 'subscription',
            'order_id' => $orderId,
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'amount' => (float) $amount,
            'currency' => $currency,
            'callback_url' => urldecode($callbackUrl),
            'app_url' => config('app.url'),
        ]);

        return [
            'url' => $url,
            'order_id' => $orderId,
            'hash' => $hash,
            'amount' => (float) $amount,
            'currency' => $currency,
            'merchant_id' => $config['merchant_id'],
            'mode' => $config['mode'],
            'meta' => [
                'plan_id' => $plan->id,
                'user_id' => $user->id,
            ],
        ];
    }

    /**
     * Create checkout session for wallet top-up (T095).
     * Order ID format: wlt_{userId}_{timestamp}
     *
     * @return array{url: string, order_id: string, hash: string, amount: float, currency: string}
     */
    public function createWalletTopUpSession(User $user, float $amount): array
    {
        $config = $this->getConfig();
        $this->validateConfig($config);

        $orderId = 'wlt_' . $user->id . '_' . time();
        $currency = 'EGP';
        $amountFormatted = number_format((float) $amount, 2, '.', '');

        $hash = $this->generateOrderHash(
            $config['merchant_id'],
            $orderId,
            $amountFormatted,
            $currency,
            $config['api_key']
        );

        $baseUrl = $config['mode'] === 'live' ? self::BASE_URL_LIVE : self::BASE_URL_TEST;
        $callbackUrl = urlencode($this->buildCallbackUrl($orderId));

        $url = $baseUrl
            . '?merchantId=' . $config['merchant_id']
            . '&orderId=' . $orderId
            . '&mode=' . $config['mode']
            . '&amount=' . $amountFormatted
            . '&currency=' . $currency
            . '&hash=' . $hash
            . '&merchantRedirect=' . $callbackUrl
            . '&allowedMethods=card,wallet,bank'
            . '&display=en'
            . '&saveCard=true'
            . '&customerReference=usr_' . $user->id;

        return [
            'url' => $url,
            'order_id' => $orderId,
            'hash' => $hash,
            'amount' => (float) $amount,
            'currency' => $currency,
            'merchant_id' => $config['merchant_id'],
            'mode' => $config['mode'],
            'meta' => ['user_id' => $user->id, 'type' => 'wallet_topup'],
        ];
    }

    /**
     * Create checkout session for Webinar Registration.
     * Order ID format: webinar_{webinarId}_{userId}_{timestamp}
     *
     * @return array{url: string, order_id: string, hash: string, amount: float, currency: string}
     */
    public function createWebinarCheckoutSession(int $webinarId, User $user, float $amount): array
    {
        $config = $this->getConfig();
        $this->validateConfig($config);

        $orderId = 'webinar_' . $webinarId . '_' . $user->id . '_' . time();
        $currency = 'EGP';
        $amountFormatted = number_format((float) $amount, 2, '.', '');

        $hash = $this->generateOrderHash(
            $config['merchant_id'],
            $orderId,
            $amountFormatted,
            $currency,
            $config['api_key']
        );

        $baseUrl = $config['mode'] === 'live' ? self::BASE_URL_LIVE : self::BASE_URL_TEST;
        $callbackUrl = urlencode($this->buildCallbackUrl($orderId));

        $url = $baseUrl
            . '?merchantId=' . $config['merchant_id']
            . '&orderId=' . $orderId
            . '&mode=' . $config['mode']
            . '&amount=' . $amountFormatted
            . '&currency=' . $currency
            . '&hash=' . $hash
            . '&merchantRedirect=' . $callbackUrl
            . '&allowedMethods=card,wallet,bank'
            . '&display=en'
            . '&saveCard=true'
            . '&customerReference=usr_' . $user->id;

        return [
            'url' => $url,
            'order_id' => $orderId,
            'hash' => $hash,
            'amount' => (float) $amount,
            'currency' => $currency,
            'merchant_id' => $config['merchant_id'],
            'mode' => $config['mode'],
            'meta' => ['user_id' => $user->id, 'webinar_id' => $webinarId, 'type' => 'webinar_registration'],
        ];
    }

    /**
     * Verify webhook/callback signature from Kashier.
     */
    public function verifyPayment(array $payload): bool
    {
        $config = $this->getConfig();
        if (empty($config['api_key'])) {
            return false;
        }

        // Handle Kashier webhook structure (nested data object)
        $data = $payload;
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
            // Signature is usually in the top level for webhooks, but data is what's signed
            $signature = $payload['signature'] ?? $data['signature'] ?? '';
        } else {
            $signature = $payload['signature'] ?? '';
        }

        if (empty($signature)) {
            return false;
        }

        // Kashier signature rules:
        // 1. Take all fields except signature and mode
        // 2. Sort them alphabetically
        // 3. Join with & (key=value)
        // 4. Hash with API Key using SHA256
        $queryParts = [];
        ksort($data);

        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), ['signature', 'mode'], true) || is_array($value)) {
                continue;
            }
            $queryParts[] = $key . '=' . $value;
        }
        $queryString = implode('&', $queryParts);

        $expectedSignature = hash_hmac('sha256', $queryString, $config['api_key'], false);
        $isValid = hash_equals($expectedSignature, $signature);

        if (!$isValid) {
            Log::warning('Kashier signature verification failed', [
                'expected' => $expectedSignature,
                'received' => $signature,
                'queryString' => $queryString
            ]);
        }

        return $isValid;
    }

    /**
     * Get payment status from Kashier API (if available).
     * Returns status string: pending, completed, failed, etc.
     */
    public function getPaymentStatus(string $transactionId): string
    {
        $config = $this->getConfig();
        if (empty($config['api_key'])) {
            return 'unknown';
        }

        try {
            // Kashier Management API uses api.kashier.io
            $response = Http::withHeaders([
                'Authorization' => $config['api_key'],
            ])->timeout(10)->get('https://api.kashier.io/v1/transaction/' . $transactionId);

            if ($response->successful()) {
                $data = $response->json();
                // Kashier API response structure: { "status": "success", "response": { "status": "captured", ... } }
                // or similar. Let's be flexible and check common paths.
                $status = $data['response']['status'] ?? $data['status'] ?? 'unknown';
                
                Log::info('Kashier API status check', [
                    'transactionId' => $transactionId,
                    'status' => $status
                ]);

                return strtolower((string) $status);
            }
            
            Log::warning('Kashier API status check failed', [
                'transactionId' => $transactionId,
                'response' => $response->body()
            ]);
        } catch (\Throwable $e) {
            Log::warning('Kashier getPaymentStatus exception: ' . $e->getMessage());
        }

        return 'unknown';
    }
    /**
     * Get full payment details from Kashier API using a transaction id.
     */
    public function getPaymentDetails(string $transactionId): ?array
    {
        $config = $this->getConfig();
        if (empty($config['api_key'])) {
            return null;
        }

        $baseUrl = $config['mode'] === 'live' ? 'https://api.kashier.io' : 'https://test-api.kashier.io';

        try {
            $response = Http::withHeaders([
                'Authorization' => $config['api_key'],
            ])->timeout(10)->get($baseUrl . '/v1/transaction/' . $transactionId);

            if ($response->successful()) {
                return $this->parsePaymentDetails($response->json());
            }

            Log::warning('Kashier getPaymentDetails failed', [
                'transactionId' => $transactionId,
                'response' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Kashier getPaymentDetails exception: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Best-effort lookup when Kashier redirect only returns merchant order id.
     */
    public function getPaymentDetailsByOrderId(string $orderId): ?array
    {
        $config = $this->getConfig();
        if (empty($config['api_key'])) {
            return null;
        }

        $baseUrl = $config['mode'] === 'live' ? 'https://api.kashier.io' : 'https://test-api.kashier.io';
        $headers = ['Authorization' => $config['api_key']];
        $urls = [
            $baseUrl . '/v1/transaction?merchantOrderId=' . urlencode($orderId),
            $baseUrl . '/v1/transactions?merchantOrderId=' . urlencode($orderId),
            $baseUrl . '/v1/transaction/order/' . urlencode($orderId),
        ];

        foreach ($urls as $url) {
            try {
                $response = Http::withHeaders($headers)->timeout(10)->get($url);

                if ($response->successful()) {
                    $details = $this->parsePaymentDetails($response->json());
                    if ($details !== null) {
                        Log::info('Kashier API order lookup succeeded', [
                            'order_id' => $orderId,
                            'url' => $url,
                            'status' => $details['status'] ?? null,
                        ]);

                        return $details;
                    }
                }

                Log::warning('Kashier API order lookup failed', [
                    'order_id' => $orderId,
                    'url' => $url,
                    'response' => $response->body(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Kashier API order lookup exception', [
                    'order_id' => $orderId,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }


    private function buildCallbackUrl(string $orderId): string
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        return $appUrl . '/api/webhooks/kashier?order_id=' . urlencode($orderId);
    }

    private function parsePaymentDetails(array $data): ?array
    {
        $res = $data['response'] ?? $data['data'] ?? $data['result'] ?? $data;

        if (isset($res[0]) && is_array($res[0])) {
            $res = $res[0];
        }

        if (!is_array($res)) {
            return null;
        }

        return [
            'status' => strtolower((string) (
                $res['paymentStatus']
                ?? $res['payment_status']
                ?? $res['transactionStatus']
                ?? $res['transaction_status']
                ?? $res['status']
                ?? $data['status']
                ?? 'unknown'
            )),
            'amount' => (float) ($res['amount'] ?? $res['transactionAmount'] ?? $res['transaction_amount'] ?? 0),
            'currency' => $res['currency'] ?? 'EGP',
            'order_id' => $res['merchantOrderId'] ?? $res['merchant_order_id'] ?? $res['orderId'] ?? $res['order_id'] ?? null,
            'transaction_id' => $res['transactionId'] ?? $res['transaction_id'] ?? $res['paymentId'] ?? $res['payment_id'] ?? null,
            'cardData' => $res['cardData'] ?? $res['card_data'] ?? $res['card'] ?? $res['sourceOfFund'] ?? $res['source_of_fund'] ?? $res['paymentMethod'] ?? $res['payment_method'] ?? null,
            'maskedCard' => $res['maskedCard'] ?? $res['masked_card'] ?? $res['maskedPan'] ?? $res['masked_pan'] ?? $res['cardNumber'] ?? $res['card_number'] ?? null,
            'last4' => $res['last4'] ?? $res['lastFour'] ?? $res['last_four'] ?? $res['lastFourDigits'] ?? $res['last_four_digits'] ?? null,
            'cardBrand' => $res['cardBrand'] ?? $res['card_brand'] ?? $res['brand'] ?? $res['scheme'] ?? null,
            'expiryMonth' => $res['expiryMonth'] ?? $res['expiry_month'] ?? $res['expMonth'] ?? $res['exp_month'] ?? null,
            'expiryYear' => $res['expiryYear'] ?? $res['expiry_year'] ?? $res['expYear'] ?? $res['exp_year'] ?? null,
            'cardToken' => $res['cardToken'] ?? $res['card_token'] ?? $res['token'] ?? $res['paymentToken'] ?? $res['payment_token'] ?? null,
            'raw_response' => $data,
        ];
    }


    private function kashierLog(string $message, array $context = []): void
    {
        $line = '[' . now()->format('Y-m-d H:i:s') . '] ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $written = false;

        try {
            $written = @file_put_contents(storage_path('logs/kashier.log'), $line, FILE_APPEND | LOCK_EX) !== false;
        } catch (\Throwable) {
            $written = false;
        }

        if (!$written) {
            @file_put_contents('/tmp/kashier.log', $line, FILE_APPEND | LOCK_EX);
            error_log($line);
        }
    }

    private function getConfig(): array
    {
        $mode = CachingService::getSystemSettings('kashier_mode') ?: 'test';

        return [
            'merchant_id' => (string) CachingService::getSystemSettings('kashier_merchant_id'),
            'api_key' => (string) CachingService::getSystemSettings('kashier_api_key'),
            'webhook_secret' => (string) CachingService::getSystemSettings('kashier_webhook_secret'),
            'mode' => $mode === 'live' ? 'live' : 'test',
            'status' => (int) CachingService::getSystemSettings('kashier_status'),
        ];
    }

    private function validateConfig(array $config): void
    {
        if (isset($config['status']) && $config['status'] !== 1) {
            throw new \RuntimeException('Kashier payment gateway is currently disabled.');
        }

        if (empty($config['merchant_id']) || empty($config['api_key'])) {
            throw new \RuntimeException('Kashier credentials not configured. Please set kashier_merchant_id and kashier_api_key in settings.');
        }
    }

    private function generateOrderHash(string $mid, string $orderId, string $amount, string $currency, string $secret): string
    {
        $path = '/?payment=' . $mid . '.' . $orderId . '.' . $amount . '.' . $currency;

        return hash_hmac('sha256', $path, $secret, false);
    }
}

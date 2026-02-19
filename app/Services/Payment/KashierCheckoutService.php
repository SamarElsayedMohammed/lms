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
 * Egyptian payment gateway supporting EGP.
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
    public function createCheckoutSession(SubscriptionPlan $plan, User $user, float $amount): array
    {
        $config = $this->getConfig();
        $this->validateConfig($config);

        $orderId = 'sub_' . $plan->id . '_' . $user->id . '_' . time();
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
        $callbackUrl = urlencode(url('/webhooks/kashier'));

        $url = $baseUrl
            . '?merchantId=' . $config['merchant_id']
            . '&orderId=' . $orderId
            . '&mode=' . $config['mode']
            . '&amount=' . $amountFormatted
            . '&currency=' . $currency
            . '&hash=' . $hash
            . '&merchantRedirect=' . $callbackUrl
            . '&allowedMethods=card,wallet,bank'
            . '&display=en';

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
        $callbackUrl = urlencode(url('/webhooks/kashier'));

        $url = $baseUrl
            . '?merchantId=' . $config['merchant_id']
            . '&orderId=' . $orderId
            . '&mode=' . $config['mode']
            . '&amount=' . $amountFormatted
            . '&currency=' . $currency
            . '&hash=' . $hash
            . '&merchantRedirect=' . $callbackUrl
            . '&allowedMethods=card,wallet,bank'
            . '&display=en';

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
     * Verify webhook/callback signature from Kashier.
     */
    public function verifyPayment(array $data): bool
    {
        $signature = $data['signature'] ?? '';
        if (empty($signature)) {
            return false;
        }

        $config = $this->getConfig();
        if (empty($config['api_key'])) {
            return false;
        }

        $queryParts = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), ['signature', 'mode'], true)) {
                continue;
            }
            $queryParts[] = $key . '=' . $value;
        }
        $queryString = implode('&', $queryParts);

        $expectedSignature = hash_hmac('sha256', $queryString, $config['api_key'], false);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get payment status from Kashier API (if available).
     * Returns status string: pending, completed, failed, etc.
     */
    public function getPaymentStatus(string $transactionId): string
    {
        $config = $this->getConfig();
        if (empty($config['api_key']) || empty($config['merchant_id'])) {
            return 'unknown';
        }

        try {
            $baseUrl = $config['mode'] === 'live' ? self::BASE_URL_LIVE : self::BASE_URL_TEST;
            $response = Http::timeout(10)->get($baseUrl . '/api/transaction/' . $transactionId, [
                'merchantId' => $config['merchant_id'],
                'apiKey' => $config['api_key'],
            ]);

            if ($response->successful()) {
                $body = $response->json();
                return $body['status'] ?? 'unknown';
            }
        } catch (\Throwable $e) {
            Log::warning('Kashier getPaymentStatus failed: ' . $e->getMessage());
        }

        return 'unknown';
    }

    private function getConfig(): array
    {
        $mode = CachingService::getSystemSettings('kashier_mode') ?: 'test';

        return [
            'merchant_id' => (string) CachingService::getSystemSettings('kashier_merchant_id'),
            'api_key' => (string) CachingService::getSystemSettings('kashier_api_key'),
            'webhook_secret' => (string) CachingService::getSystemSettings('kashier_webhook_secret'),
            'mode' => $mode === 'live' ? 'live' : 'test',
        ];
    }

    private function validateConfig(array $config): void
    {
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

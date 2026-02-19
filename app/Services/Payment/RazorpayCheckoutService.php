<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Services\HelperService;
use Razorpay\Api\Api;

class RazorpayCheckoutService implements PaymentGatewayContract
{
    #[\Override]
    public function initiate(Order $order, array $options = []): array
    {
        // Get Razorpay settings from database
        $razorpaySettings = HelperService::systemSettings([
            'razorpay_api_key',
            'razorpay_secret_key',
            'razorpay_status',
        ]);

        // Check if Razorpay is enabled
        if (empty($razorpaySettings['razorpay_status']) || $razorpaySettings['razorpay_status'] != 1) {
            throw new \Exception('Razorpay payment gateway is not enabled');
        }

        // Validate required settings
        if (empty($razorpaySettings['razorpay_api_key']) || empty($razorpaySettings['razorpay_secret_key'])) {
            throw new \Exception('Razorpay API keys are not configured');
        }

        $currency = strtoupper($options['currency']['currency_code'] ?? 'INR');

        $api = new Api($razorpaySettings['razorpay_api_key'], $razorpaySettings['razorpay_secret_key']);

        // Amount in paise for INR (and generally in the smallest unit)
        $amount = (int) round($currency === 'INR' ? $order->final_price * 100 : $order->final_price * 100);

        $rzpOrder = $api->order->create([
            'receipt' => $order->order_number,
            'amount' => $amount,
            'currency' => $currency,
            'notes' => [
                'order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'user_id' => (string) $order->user_id,
            ],
        ]);

        // Get customer details from order or options
        $customerEmail = $options['customer']['email'] ?? $order->user->email ?? '';
        $customerName = $options['customer']['name'] ?? $order->user->name ?? '';

        // Get type parameter from options (web/app)
        $type = $options['type'] ?? 'web';

        // Generate payment URL for Razorpay
        $paymentUrl = $this->generatePaymentUrl(
            $rzpOrder['id'],
            $amount,
            $currency,
            $customerName,
            $customerEmail,
            $order->order_number,
            $type,
        );

        return [
            'provider' => 'razorpay',
            'id' => $rzpOrder['id'],
            'url' => $paymentUrl,
            'meta' => [
                'key' => $razorpaySettings['razorpay_api_key'],
                'amount' => $amount,
                'currency' => $currency,
                'order_id' => $rzpOrder['id'],
                'name' => config('app.name'),
                'description' => 'Courses (Order ' . $order->order_number . ')',
                'prefill' => [
                    'name' => $customerName,
                    'email' => $customerEmail,
                ],
            ],
        ];
    }

    /**
     * Generate Razorpay payment URL
     */
    private function generatePaymentUrl(
        $orderId,
        $amount,
        $currency,
        $customerName,
        $customerEmail,
        $orderNumber,
        $type = 'web',
    ) {
        // Create a payment page URL with Razorpay parameters
        $baseUrl = config('app.url');
        $params = http_build_query([
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'name' => config('app.name'),
            'description' => 'Courses (Order ' . $orderNumber . ')',
            'prefill_name' => $customerName,
            'prefill_email' => $customerEmail,
            'order_number' => $orderNumber,
            'type' => $type,
            'theme' => [
                'color' => '#F37254',
            ],
        ]);

        return $baseUrl . '/razorpay/payment?' . $params;
    }
}

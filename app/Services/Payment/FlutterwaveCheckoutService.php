<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Services\HelperService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlutterwaveCheckoutService implements PaymentGatewayContract
{
    #[\Override]
    public function initiate(Order $order, array $options = []): array
    {
        // Get Flutterwave settings from database
        $flutterwaveSettings = HelperService::systemSettings([
            'flutterwave_public_key',
            'flutterwave_secret_key',
            'flutterwave_encryption_key',
            'flutterwave_currency',
            'flutterwave_status',
        ]);

        // Check if Flutterwave is enabled
        if (empty($flutterwaveSettings['flutterwave_status']) || $flutterwaveSettings['flutterwave_status'] != 1) {
            throw new \Exception('Flutterwave payment gateway is not enabled');
        }

        // Validate required settings
        if (
            empty($flutterwaveSettings['flutterwave_public_key'])
            || empty($flutterwaveSettings['flutterwave_secret_key'])
            || empty($flutterwaveSettings['flutterwave_encryption_key'])
        ) {
            throw new \Exception('Flutterwave API keys are not configured');
        }

        $currency = $flutterwaveSettings['flutterwave_currency'] ?? 'NGN';
        $amount = $order->final_price;

        // Get customer details from order or options
        $customerEmail = $options['customer']['email'] ?? $order->user->email ?? '';
        $customerName = $options['customer']['name'] ?? $order->user->name ?? '';
        $customerPhone = $options['customer']['phone'] ?? '';

        // Generate unique transaction reference
        $txRef = 'TXN_' . $order->order_number . '_' . time();

        // Get type parameter from options (web/app)
        $type = $options['type'] ?? 'web';

        // Create payment data
        $paymentData = [
            'tx_ref' => $txRef,
            'amount' => $amount,
            'currency' => $currency,
            'redirect_url' => url('/flutterwave-callback') . '?order=' . $order->order_number . '&type=' . $type,
            'customer' => [
                'email' => $customerEmail,
                'name' => $customerName,
                'phone_number' => $customerPhone,
            ],
            'customizations' => [
                'title' => config('app.name'),
                'description' => 'Courses (Order ' . $order->order_number . ')',
                'logo' => asset('img/logo.png'), // You can customize this
            ],
            'meta' => [
                'order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'user_id' => (string) $order->user_id,
            ],
        ];

        // Make API call to Flutterwave
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $flutterwaveSettings['flutterwave_secret_key'],
            'Content-Type' => 'application/json',
        ])->post('https://api.flutterwave.com/v3/payments', $paymentData);

        if (!$response->successful()) {
            throw new \Exception('Failed to initialize Flutterwave payment: ' . $response->body());
        }

        $responseData = $response->json();

        // Log the response for debugging
        Log::info('Flutterwave API Response:', $responseData);

        if ($responseData['status'] !== 'success') {
            throw new \Exception(
                'Flutterwave payment initialization failed: ' . ($responseData['message'] ?? 'Unknown error'),
            );
        }

        // Check if the required data exists
        if (!isset($responseData['data']) || !is_array($responseData['data'])) {
            throw new \Exception('Invalid Flutterwave response structure: ' . json_encode($responseData));
        }

        $data = $responseData['data'];

        // Use the tx_ref from our request if not available in response
        $txRef = $data['tx_ref'] ?? $paymentData['tx_ref'];
        $link = $data['link'] ?? null;

        if (!$link) {
            throw new \Exception('Flutterwave payment link not found in response. Response data: '
            . json_encode($data));
        }

        return [
            'provider' => 'flutterwave',
            'id' => $txRef,
            'url' => $link,
            'meta' => [
                'public_key' => $flutterwaveSettings['flutterwave_public_key'],
                'tx_ref' => $txRef,
                'amount' => $amount,
                'currency' => $currency,
                'customer' => $paymentData['customer'],
                'customizations' => $paymentData['customizations'],
            ],
        ];
    }
}

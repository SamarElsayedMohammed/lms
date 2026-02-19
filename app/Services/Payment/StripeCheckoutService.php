<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Services\HelperService;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class StripeCheckoutService implements PaymentGatewayContract
{
    #[\Override]
    public function initiate(Order $order, array $options = []): array
    {
        // Get Stripe settings from database
        $stripeSettings = HelperService::systemSettings([
            'stripe_secret_key',
            'stripe_publishable_key',
            'stripe_currency',
            'stripe_status',
        ]);

        // Check if Stripe is enabled
        if (empty($stripeSettings['stripe_status']) || $stripeSettings['stripe_status'] != 1) {
            throw new \Exception('Stripe payment gateway is not enabled');
        }

        // Validate required settings
        if (empty($stripeSettings['stripe_secret_key'])) {
            throw new \Exception('Stripe secret key is not configured');
        }

        $currency = $stripeSettings['stripe_currency'] ?? 'USD';

        // Get type parameter from options (web/app)
        $type = $options['type'] ?? 'web';

        $successUrl =
            url('/stripe-callback')
            . '?session_id={CHECKOUT_SESSION_ID}&order='
            . $order->order_number
            . '&type='
            . $type;
        $cancelUrl = url('/stripe-cancel') . '?order=' . $order->order_number . '&type=' . $type;

        Stripe::setApiKey($stripeSettings['stripe_secret_key']);

        // Handle zero-decimal amounts
        $zeroDecimal = in_array($currency, [
            'BIF',
            'CLP',
            'DJF',
            'GNF',
            'JPY',
            'KMF',
            'KRW',
            'MGA',
            'PYG',
            'RWF',
            'UGX',
            'VND',
            'VUV',
            'XAF',
            'XOF',
            'XPF',
        ]);
        $amount = $order->final_price;
        $unitAmount = $zeroDecimal ? (int) round($amount) : (int) round($amount * 100);

        // Get customer details from order or options
        $customerEmail = $options['customer_email'] ?? $order->user->email ?? null;
        $customerName = $options['customer_name'] ?? $order->user->name ?? null;
        $customerAddr = $options['customer_address'] ?? null;

        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => ['name' => 'Courses (Order ' . $order->order_number . ')'],
                    'unit_amount' => $unitAmount,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'billing_address_collection' => 'required',
            'customer_creation' => 'always',
            'customer_email' => $customerEmail,
            'metadata' => [
                'order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'user_id' => (string) $order->user_id,
                'customer_name' => $customerName,
                'customer_address' => $customerAddr,
            ],
        ]);

        return [
            'provider' => 'stripe',
            'id' => $session->id,
            'url' => $session->url,
            'meta' => [],
        ];
    }
}

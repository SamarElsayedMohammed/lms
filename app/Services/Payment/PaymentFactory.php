<?php

namespace App\Services\Payment;

class PaymentFactory
{
    public function for(string $method): PaymentGatewayContract
    {
        return match ($method) {
            'stripe' => app(StripeCheckoutService::class),
            'razorpay' => app(RazorpayCheckoutService::class),
            'flutterwave' => app(FlutterwaveCheckoutService::class),
            'kashier' => app(KashierCheckoutService::class),
            //'cash'     => app(CashOnDeliveryService::class),
            default => throw new \InvalidArgumentException('Unsupported payment method'),
        };
    }
}

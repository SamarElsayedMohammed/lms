<?php

namespace App\Services\Payment;

use InvalidArgumentException;

class PaymentService
{
    /**
     * @param string $paymentGateway
     * @return PaymentInterface
     */
    public static function create(array $paymentGateway)
    {
        $paymentMethod = strtolower($paymentGateway['payment_method']);
        return match ($paymentMethod) {
            'razorpay' => new RazorpayPayment($paymentGateway),
            default => throw new InvalidArgumentException('Invalid Payment Gateway.'),
        };
    }

    /***
     * @param string $paymentGateway
     * @param $paymentIntentData
     * @return array
     * Stripe Payment Intent : https://stripe.com/docs/api/payment_intents/object
     */
    //    public static function formatPaymentIntent(string $paymentGateway, $paymentIntentData) {
    //        $paymentGateway = strtolower($paymentGateway);
    //        return match ($paymentGateway) {
    //            'stripe' => [
    //                'id'                       => $paymentIntentData->id,
    //                'amount'                   => $paymentIntentData->amount,
    //                'currency'                 => $paymentIntentData->currency,
    //                'metadata'                 => $paymentIntentData->metadata,
    //                'status'                   => match ($paymentIntentData->status) {
    //                    "canceled" => "failed",
    //                    "succeeded" => "succeed",
    //                    "processing", "requires_action", "requires_capture", "requires_confirmation", "requires_payment_method" => "pending",
    //                },
    //                'payment_gateway_response' => $paymentIntentData
    //            ],
    //
    //            'paystack' => [
    //                'id'                       => $paymentIntentData['data']['reference'],
    //                'amount'                   => $paymentIntentData->amount,
    //                'currency'                 => $paymentIntentData->currency,
    //                'metadata'                 => $paymentIntentData->metadata,
    //                'status'                   => match ($paymentIntentData['data']['status']) {
    //                    "abandoned" => "failed",
    //                    "succeed" => "succeed",
    //                    default => $paymentIntentData['data']['status'] ?? true
    //                },
    //                'payment_gateway_response' => $paymentIntentData
    //            ],
    //            // any other payment processor implementations
    //            default => $paymentIntentData,
    //        };
    //    }
}

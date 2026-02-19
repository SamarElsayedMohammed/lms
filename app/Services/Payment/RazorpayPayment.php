<?php

namespace App\Services\Payment;

use App\Services\HelperService;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api;
use RuntimeException;
use Throwable;

class RazorpayPayment implements PaymentInterface
{
    private readonly Api $api;
    private readonly string $currencyCode;

    /**
     * RazorpayPayment constructor.
     * @param $secretKey
     * @param $publicKey
     * @param $currencyCode
     */
    public function __construct($paymentData)
    {
        $publicKey = $paymentData['razorpay_api_key'];
        $secretKey = $paymentData['razorpay_secret_key'];
        $currencyCode = HelperService::systemSettings('currency_code');
        // Call Razorpay Class and Create Payment Intent
        $this->api = new Api($publicKey, $secretKey);
        $this->currencyCode = $currencyCode;
    }

    /**
     * @param $amount
     * @param $customMetaData
     * @return mixed
     */
    #[\Override]
    public function createPaymentIntent($amount, $customMetaData)
    {
        try {
            $orderData = [
                'amount' => $this->minimumAmountValidation($this->currencyCode, $amount),
                'currency' => $this->currencyCode,
                'notes' => $customMetaData,
            ];
            return $this->api->order->create($orderData);
        } catch (Throwable $e) {
            Log::error('Failed to create payment intent: ' . $e->getMessage());
            throw new RuntimeException($e->getMessage());
        }
    }

    /**
     * @param $amount
     * @param $customMetaData
     * @return array
     */
    #[\Override]
    public function createAndFormatPaymentIntent($amount, $customMetaData): array
    {
        $response = $this->createPaymentIntent($amount, $customMetaData);
        return $this->format($response);
    }

    /**
     * @param $paymentId
     * @return array
     * @throws Throwable
     */
    #[\Override]
    public function retrievePaymentIntent($paymentId): array
    {
        try {
            return $this->api->order->fetch($paymentId);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * @param $currency
     * @param $amount
     * @return float|int
     */
    #[\Override]
    public function minimumAmountValidation($currency, $amount)
    {
        return match ($currency) {
            'BHD', 'IQD', 'JOD', 'KWD', 'OMR', 'TND' => $amount * 1000,
            'AED',
            'ALL',
            'AMD',
            'ARS',
            'AUD',
            'AWG',
            'AZN',
            'BAM',
            'BBD',
            'BDT',
            'BGN',
            'BMD',
            'BND',
            'BOB',
            'BRL',
            'BSD',
            'BTN',
            'BWP',
            'BZD',
            'CAD',
            'CHF',
            'CNY',
            'COP',
            'CRC',
            'CUP',
            'CVE',
            'CZK',
            'DKK',
            'DOP',
            'DZD',
            'EGP',
            'ETB',
            'EUR',
            'FJD',
            'GBP',
            'GHS',
            'GIP',
            'GMD',
            'GTQ',
            'GYD',
            'HKD',
            'HNL',
            'HTG',
            'HUF',
            'IDR',
            'ILS',
            'INR',
            'JMD',
            'KES',
            'KGS',
            'KHR',
            'KYD',
            'KZT',
            'LAK',
            'LKR',
            'LRD',
            'LSL',
            'MAD',
            'MDL',
            'MGA',
            'MKD',
            'MMK',
            'MNT',
            'MOP',
            'MUR',
            'MVR',
            'MWK',
            'MXN',
            'MYR',
            'MZN',
            'NAD',
            'NGN',
            'NIO',
            'NOK',
            'NPR',
            'NZD',
            'PEN',
            'PGK',
            'PHP',
            'PKR',
            'PLN',
            'QAR',
            'RON',
            'RSD',
            'RUB',
            'SAR',
            'SCR',
            'SEK',
            'SGD',
            'SLL',
            'SOS',
            'SSP',
            'SVC',
            'SZL',
            'THB',
            'TTD',
            'TWD',
            'TZS',
            'UAH',
            'USD',
            'UYU',
            'UZS',
            'XCD',
            'YER',
            'ZAR',
            'ZMW',
                => $amount * 100,
            'BIF',
            'CLP',
            'DJF',
            'GNF',
            'ISK',
            'JPY',
            'KMF',
            'KRW',
            'PYG',
            'RWF',
            'UGX',
            'VND',
            'VUV',
            'XAF',
            'XOF',
            'XPF',
            'HRK',
                => $amount,
        };
    }

    /**
     * @param $paymentIntent
     * @return array
     */
    private function format($paymentIntent)
    {
        return $this->formatPaymentIntent(
            $paymentIntent->id,
            $paymentIntent->amount,
            $paymentIntent->currency,
            $paymentIntent->status,
            $paymentIntent->notes->toArray(),
            $paymentIntent->toArray(),
        );
    }

    /**
     * @param $id
     * @param $amount
     * @param $currency
     * @param $status
     * @param $metadata
     * @param $paymentIntent
     * @return array
     */
    #[\Override]
    public function formatPaymentIntent($id, $amount, $currency, $status, $metadata, $paymentIntent): array
    {
        return [
            'id' => $id,
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => $metadata,
            'status' => match ($status) {
                'failed' => 'failed', //NOTE : Failed status is not known, please test the failure status
                'created', 'attempted' => 'pending',
                'paid' => 'succeed',
            },
            'payment_gateway_response' => $paymentIntent,
        ];
    }
}

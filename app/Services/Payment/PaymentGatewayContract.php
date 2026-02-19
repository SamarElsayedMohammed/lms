<?php

namespace App\Services\Payment;

use App\Models\Order;

interface PaymentGatewayContract
{
    /**
     * Return a standardized payload, e.g.:
     * [
     *   'provider' => 'stripe',
     *   'id'       => 'cs_test_...',
     *   'url'      => 'https://checkout.stripe.com/...',
     *   'meta'     => [...]
     * ]
     */
    public function initiate(Order $order, array $options = []): array;
}

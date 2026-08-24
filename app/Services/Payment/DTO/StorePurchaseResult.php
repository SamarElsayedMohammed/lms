<?php

declare(strict_types=1);

namespace App\Services\Payment\DTO;

use Carbon\Carbon;

final class StorePurchaseResult
{
    public function __construct(
        public readonly string $store, // 'app_store' | 'google_play'
        public readonly string $environment, // 'sandbox' | 'production'
        public readonly string $storeProductId,
        public readonly string $transactionId,
        public readonly string $originalTransactionId,
        public readonly ?string $purchaseToken,
        public readonly Carbon $purchasedAt,
        public readonly ?Carbon $expiresAt,
        public readonly bool $autoRenew,
        public readonly string $status, // 'active', 'expired', 'in_grace_period', 'on_hold', 'paused', 'canceled', 'revoked', 'refunded'
        public readonly bool $isVerified,
        public readonly bool $isRevoked = false,
        public readonly bool $isRefunded = false,
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly array $rawPayload = [],
        public readonly ?string $errorMessage = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->isVerified && !$this->isRevoked && !$this->isRefunded && $this->errorMessage === null;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return $this->expiresAt->isPast();
    }

    public function isValidForEntitlement(): bool
    {
        return $this->isSuccess() && !$this->isExpired() && in_array($this->status, ['active', 'in_grace_period'], true);
    }

    public static function failure(
        string $store,
        string $errorMessage,
        string $storeProductId = '',
        string $transactionId = '',
        string $originalTransactionId = '',
        array $rawPayload = []
    ): self {
        return new self(
            store: $store,
            environment: 'unknown',
            storeProductId: $storeProductId,
            transactionId: $transactionId,
            originalTransactionId: $originalTransactionId,
            purchaseToken: null,
            purchasedAt: Carbon::now(),
            expiresAt: null,
            autoRenew: false,
            status: 'failed',
            isVerified: false,
            isRevoked: false,
            isRefunded: false,
            rawPayload: $rawPayload,
            errorMessage: $errorMessage,
        );
    }
}

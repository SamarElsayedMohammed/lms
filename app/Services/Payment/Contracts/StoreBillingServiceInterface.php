<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

use App\Models\User;
use App\Services\Payment\DTO\StorePurchaseResult;

interface StoreBillingServiceInterface
{
    /**
     * Verify store purchase proof and return a normalized StorePurchaseResult.
     *
     * @param array<string, mixed> $proof Minimal client proof data
     * @param User|null $user The authenticated Skillso user
     * @return StorePurchaseResult
     */
    public function verify(array $proof, ?User $user = null): StorePurchaseResult;
}

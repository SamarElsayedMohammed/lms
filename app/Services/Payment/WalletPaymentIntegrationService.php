<?php

namespace App\Services\Payment;

use App\Models\User;
use App\Models\Webinar;
use App\Models\WalletHistory;
use App\Services\WalletService;
use Exception;
use Illuminate\Support\Facades\DB;

class WalletPaymentIntegrationService
{
    /**
     * Deduct funds for a webinar and return true if successful.
     *
     * @param User $user
     * @param Webinar $webinar
     * @return bool
     * @throws Exception
     */
    public function payForWebinar(
        User $user,
        Webinar $webinar,
        ?string $paymentReference = null,
    ): ?WalletHistory
    {
        if ($webinar->is_free || $webinar->price <= 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $webinar, $paymentReference) {
            // Lock user row to prevent race conditions during balance check
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();

            if ($lockedUser->wallet_balance < $webinar->price) {
                throw new Exception('insufficient_funds', 400);
            }

            // Perform the deduction using the existing WalletService
            return WalletService::debitWallet(
                $lockedUser->id,
                $webinar->price,
                'webinar_payment',
                'Paid for webinar: ' . $webinar->title,
                $paymentReference ?: (string) \Illuminate\Support\Str::uuid(),
                'webinar_registration_payment'
            );
        });
    }
}

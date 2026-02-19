<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletHistory;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Credit amount to user's wallet
     */
    public static function creditWallet(
        $userId,
        $amount,
        $transactionType,
        $description = null,
        $referenceId = null,
        $referenceType = null,
        $entryType = null,
    ) {
        return DB::transaction(static function () use (
            $userId,
            $amount,
            $transactionType,
            $description,
            $referenceId,
            $referenceType,
            $entryType,
        ) {
            $user = User::findOrFail($userId);

            // Auto-detect entry_type if not explicitly provided (null or empty)
            if (empty($entryType)) {
                $entryType = self::detectEntryType($user, $transactionType, $referenceType);
            }

            $oldBalance = $user->wallet_balance;
            $newBalance = $oldBalance + $amount;

            $user->update(['wallet_balance' => $newBalance]);

            return WalletHistory::create([
                'user_id' => $userId,
                'amount' => $amount,
                'type' => 'credit',
                'transaction_type' => $transactionType,
                'entry_type' => $entryType,
                'reference_id' => $referenceId,
                'reference_type' => $referenceType,
                'description' => $description,
                'balance_before' => $oldBalance,
                'balance_after' => $newBalance,
            ]);
        });
    }

    /**
     * Debit amount from user's wallet
     */
    public static function debitWallet(
        $userId,
        $amount,
        $transactionType,
        $description = null,
        $referenceId = null,
        $referenceType = null,
        $entryType = null,
    ) {
        return DB::transaction(static function () use (
            $userId,
            $amount,
            $transactionType,
            $description,
            $referenceId,
            $referenceType,
            $entryType,
        ) {
            $user = User::findOrFail($userId);

            // Auto-detect entry_type if not explicitly provided (null or empty)
            if (empty($entryType)) {
                $entryType = self::detectEntryType($user, $transactionType, $referenceType);
            }

            if ($user->wallet_balance < $amount) {
                throw new \Exception('Insufficient wallet balance');
            }

            $oldBalance = $user->wallet_balance;
            $newBalance = $oldBalance - $amount;

            $user->update(['wallet_balance' => $newBalance]);

            return WalletHistory::create([
                'user_id' => $userId,
                'amount' => $amount,
                'type' => 'debit',
                'transaction_type' => $transactionType,
                'entry_type' => $entryType,
                'reference_id' => $referenceId,
                'reference_type' => $referenceType,
                'description' => $description,
                'balance_before' => $oldBalance,
                'balance_after' => $newBalance,
            ]);
        });
    }

    /**
     * Detect entry type based on user roles and transaction context
     */
    private static function detectEntryType($user, $transactionType, $referenceType = null)
    {
        // Commission transactions are always instructor
        if ($transactionType === 'commission') {
            return 'instructor';
        }

        // Check user roles
        if ($user->hasRole('Instructor')) {
            // If withdrawal and user is instructor, it's instructor withdrawal
            if ($transactionType === 'withdrawal') {
                return 'instructor';
            }
            // Other transactions from instructors are still instructor side
            return 'instructor';
        }

        if ($user->hasRole('Admin') || $user->hasRole('Staff')) {
            return 'staff';
        }

        // Default to user
        return 'user';
    }

    /**
     * Get user's wallet balance
     */
    public static function getWalletBalance($userId)
    {
        $user = User::findOrFail($userId);
        return $user->wallet_balance;
    }

    /**
     * Get user's wallet history
     */
    public static function getWalletHistory($userId, $limit = 50)
    {
        return WalletHistory::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}

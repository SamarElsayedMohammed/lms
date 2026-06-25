<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SocialLogin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class EmailPasswordResetService
{
    public const OTP_LENGTH = 6;

    public const OTP_EXPIRY_MINUTES = 15;

    public function findEligibleUser(string $email): ?User
    {
        $normalizedEmail = $this->normalizeEmail($email);

        if ($normalizedEmail === '') {
            return null;
        }

        $user = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail])
            ->where('is_active', 1)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->first();

        if ($user === null || $user->trashed() || $this->isPasswordResetBlocked($user)) {
            return null;
        }

        return $user;
    }

    public function sendOtp(User $user): string
    {
        $otp = $this->generateOtp();
        $normalizedEmail = $this->normalizeEmail((string) $user->email);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $normalizedEmail],
            [
                'token' => Hash::make($otp),
                'created_at' => now(),
            ],
        );

        $mailDriver = (string) config('mail.default', 'log');
        if ($mailDriver === 'log') {
            Log::warning('Password reset email is using the log mail driver — configure SMTP in .env', [
                'user_id' => $user->id,
            ]);
        }

        $appName = HelperService::systemSettings('app_name') ?? config('app.name');

        try {
            Mail::send(
                'emails.password-reset-otp',
                [
                    'user' => $user,
                    'otp' => $otp,
                    'appName' => $appName,
                    'expiryMinutes' => self::OTP_EXPIRY_MINUTES,
                ],
                static function ($mail) use ($user, $appName): void {
                    $mail->to($user->email)
                        ->subject(__('Password reset code') . ' - ' . $appName);
                },
            );
        } catch (\Throwable $e) {
            Log::error('Password reset OTP email failed to send', [
                'user_id' => $user->id,
                'mail_driver' => $mailDriver,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::info('Password reset OTP email sent', [
            'user_id' => $user->id,
            'mail_driver' => $mailDriver,
        ]);

        return $otp;
    }

    public function verifyOtp(string $email, string $code): bool
    {
        return $this->getValidResetRecord($email, $code) !== null;
    }

    public function resetPassword(string $email, string $code, string $password): bool
    {
        $record = $this->getValidResetRecord($email, $code);

        if ($record === null) {
            return false;
        }

        $user = $this->findEligibleUser($email);

        if ($user === null) {
            return false;
        }

        $user->forceFill([
            'password' => Hash::make($password),
        ])->save();

        $socialLogin = SocialLogin::query()
            ->where('user_id', $user->id)
            ->where('type', 'email')
            ->first();

        if ($socialLogin !== null && !empty($socialLogin->firebase_id)) {
            try {
                HelperService::updateFirebasePassword($socialLogin->firebase_id, $password);
            } catch (\Throwable $e) {
                Log::warning('Firebase password sync failed during email OTP reset', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $user->tokens()->delete();
        $this->deleteOtp($email);

        return true;
    }

    public function remainingSeconds(string $email): ?int
    {
        $record = DB::table('password_reset_tokens')
            ->where('email', $this->normalizeEmail($email))
            ->first();

        if ($record === null || $record->created_at === null) {
            return null;
        }

        $expiresAt = now()->parse($record->created_at)->addMinutes(self::OTP_EXPIRY_MINUTES);

        return max(0, $expiresAt->getTimestamp() - now()->getTimestamp());
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function isPasswordResetBlocked(User $user): bool
    {
        return $user->hasAnyRole([
            config('constants.SYSTEM_ROLES.SUPER_ADMIN'),
            config('constants.SYSTEM_ROLES.SUPERVISOR'),
            config('constants.SYSTEM_ROLES.STAFF'),
            config('constants.SYSTEM_ROLES.ACCOUNTANT'),
        ]);
    }

    private function getValidResetRecord(string $email, string $code): ?object
    {
        $record = DB::table('password_reset_tokens')
            ->where('email', $this->normalizeEmail($email))
            ->first();

        if ($record === null || $record->created_at === null) {
            return null;
        }

        if (now()->parse($record->created_at)->addMinutes(self::OTP_EXPIRY_MINUTES)->isPast()) {
            $this->deleteOtp($email);

            return null;
        }

        if (!Hash::check($code, $record->token)) {
            return null;
        }

        return $record;
    }

    private function deleteOtp(string $email): void
    {
        DB::table('password_reset_tokens')
            ->where('email', $this->normalizeEmail($email))
            ->delete();
    }

    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 10 ** self::OTP_LENGTH - 1), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }
}

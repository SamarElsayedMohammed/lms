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
        $user = User::query()
            ->where('email', $email)
            ->where('is_active', 1)
            ->role(config('constants.SYSTEM_ROLES.USER'))
            ->first();

        if ($user === null || $user->trashed()) {
            return null;
        }

        return $user;
    }

    public function sendOtp(User $user): string
    {
        $otp = $this->generateOtp();

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($otp),
                'created_at' => now(),
            ],
        );

        $appName = HelperService::systemSettings('app_name') ?? config('app.name');

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
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if ($record === null || $record->created_at === null) {
            return null;
        }

        $expiresAt = now()->parse($record->created_at)->addMinutes(self::OTP_EXPIRY_MINUTES);

        return max(0, $expiresAt->getTimestamp() - now()->getTimestamp());
    }

    private function getValidResetRecord(string $email, string $code): ?object
    {
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

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
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }

    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 10 ** self::OTP_LENGTH - 1), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }
}

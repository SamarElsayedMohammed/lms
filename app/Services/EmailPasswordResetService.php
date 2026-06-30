<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SocialLogin;
use App\Models\User;
use App\Services\Mail\BrevoTransactionalMailService;
use App\Services\Mail\MailFromResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

final class EmailPasswordResetService
{
    public function __construct(
        private readonly BrevoTransactionalMailService $brevoMailService,
        private readonly MailFromResolver $mailFromResolver,
    ) {}

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
        $subject = __('Password reset code') . ' - ' . $appName;
        $html = View::make('emails.reset-password', [
            'user' => $user,
            'otp' => $otp,
            'appName' => $appName,
            'expiryMinutes' => self::OTP_EXPIRY_MINUTES,
        ])->render();

        try {
            $from = $this->mailFromResolver->resolve();

            if ($this->brevoMailService->isConfigured()) {
                $result = $this->brevoMailService->sendHtml(
                    (string) $user->email,
                    (string) ($user->name ?? ''),
                    $subject,
                    $html,
                );

                Log::info('Password reset OTP email sent via Brevo API', [
                    'user_id' => $user->id,
                    'to' => $this->maskEmail((string) $user->email),
                    'from' => $result['from'],
                    'message_id' => $result['message_id'],
                ]);
            } else {
                $this->sendViaSmtp($user, $otp, $from, $subject, $mailDriver);
            }
        } catch (\Throwable $e) {
            Log::error('Password reset OTP email failed to send', [
                'user_id' => $user->id,
                'to' => $this->maskEmail((string) $user->email),
                'from' => $this->mailFromResolver->address() ?: null,
                'mail_driver' => $mailDriver,
                'brevo_api' => $this->brevoMailService->isConfigured(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $otp;
    }

    /**
     * @param array{address: string, name: string} $from
     */
    private function sendViaSmtp(User $user, string $otp, array $from, string $subject, string $mailDriver): void
    {
        Mail::queue(
            'emails.reset-password',
            [
                'user' => $user,
                'otp' => $otp,
                'appName' => $from['name'],
                'expiryMinutes' => self::OTP_EXPIRY_MINUTES,
            ],
            static function ($mail) use ($user, $subject, $from): void {
                $mail->from($from['address'], $from['name'])
                    ->to($user->email)
                    ->subject($subject);
            },
        );

        Log::info('Password reset OTP email sent via SMTP', [
            'user_id' => $user->id,
            'to' => $this->maskEmail((string) $user->email),
            'from' => $from['address'],
            'mail_driver' => $mailDriver,
        ]);
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = substr($local, 0, min(2, strlen($local)));

        return $visible . '***@' . $domain;
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
        // Allow all users (including admins) to reset password via email
        return false;
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

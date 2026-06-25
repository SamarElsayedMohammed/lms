<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Password reset code') }} - {{ $appName }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f5f7fb; margin: 0; padding: 24px;">
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 8px 24px rgba(0,0,0,0.06);">
        <h2 style="margin-top: 0; color: #111827;">{{ __('Hello') }} {{ $user->name }},</h2>

        <p style="color: #4b5563;">
            {{ __('Use the verification code below to reset your password.') }}
        </p>

        <div style="text-align: center; margin: 32px 0;">
            <span style="display: inline-block; font-size: 32px; letter-spacing: 8px; font-weight: bold; color: #111827; background: #f3f4f6; padding: 16px 24px; border-radius: 8px;">
                {{ $otp }}
            </span>
        </div>

        <p style="color: #6b7280; font-size: 14px;">
            {{ __('This code expires in :minutes minutes.', ['minutes' => $expiryMinutes]) }}
        </p>

        <p style="color: #6b7280; font-size: 14px;">
            {{ __('If you did not request a password reset, you can safely ignore this email.') }}
        </p>

        <div style="margin-top: 32px; font-size: 14px; color: #9ca3af;">
            <p style="margin: 0;">{{ __('Best regards') }},<br>{{ $appName }}</p>
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Reset your password') }} - {{ $appName }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2>{{ __('Hello') }} {{ $user->name }},</h2>

        <p>{{ __('You requested a password reset for your account.') }}</p>

        <p style="margin: 24px 0;">
            <a href="{{ $resetUrl }}"
               style="display: inline-block; background: #007bff; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 4px;">
                {{ __('Reset Password') }}
            </a>
        </p>

        <p style="color: #666; font-size: 0.9em;">
            {{ __('If you did not request a password reset, no further action is required.') }}
        </p>

        <div style="margin-top: 30px; font-size: 0.9em; color: #888;">
            <p>{{ __('Best regards') }},<br>{{ $appName }}</p>
        </div>
    </div>
</body>
</html>

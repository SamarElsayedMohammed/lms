<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reply from {{ $appName }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2>Hello {{ $contactMessage->first_name }},</h2>
        
        <p>Thank you for contacting us. We have received your message and here is our reply:</p>

        <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #007bff; margin-bottom: 20px;">
            {!! nl2br(e($replyMessage)) !!}
        </div>

        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        
        <p style="color: #666; font-size: 0.9em;">
            <strong>Your original message:</strong><br>
            <em style="color: #888;">{!! nl2br(e($contactMessage->message)) !!}</em>
        </p>

        <div style="margin-top: 30px; font-size: 0.9em; color: #888;">
            <p>Best regards,<br>{{ $appName }} Team</p>
        </div>
    </div>
</body>
</html>

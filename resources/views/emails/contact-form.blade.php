<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New Contact Form Submission - {{ $appName }}</title>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>New Contact Form Submission</h1>
            <p>{{ $appName }}</p>
        </div>

        <div class="field">
            <div class="field-label">Name:</div>
            <div>{{ $contactMessage->first_name }}</div>
        </div>

        <div class="field">
            <div class="field-label">Email:</div>
            <div><a href="mailto:{{ $contactMessage->email }}">{{ $contactMessage->email }}</a></div>
        </div>

        <div class="field">
            <div class="field-label">Message:</div>
            <div class="message-content">{{ $contactMessage->message }}</div>
        </div>

        <div class="field">
            <div class="field-label">Submitted At:</div>
            <div>{{ $contactMessage->created_at->format('F j, Y \a\t g:i A') }}</div>
        </div>

        @if($contactMessage->ip_address)
        <div class="field">
            <div class="field-label">IP Address:</div>
            <div>{{ $contactMessage->ip_address }}</div>
        </div>
        @endif

        <div class="footer">
            <p>This email was sent from {{ $appName }} contact form.</p>
            <p>Message ID: #{{ $contactMessage->id }}</p>
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Team Invitation - {{ $appName }}</title>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Team Invitation</h1>
            <p>{{ $appName }}</p>
        </div>

        <div class="content">
            <p>Hello {{ $user->name }},</p>
            
            <p>You have been invited by <strong>{{ $instructor->name }}</strong> to join their instructor team.</p>
            
            <p>By accepting this invitation, you will:</p>
            <ul>
                <li>Join {{ $instructor->name }}'s instructor team</li>
                <li>Be assigned the Instructor role (if you don't already have it)</li>
                <li>Be able to collaborate on courses and manage team resources</li>
            </ul>
            
            <p><strong>Note:</strong> You must have an Instructor role to accept this invitation.</p>
            
            <div style="text-align: center;">
                <a href="{{ $acceptUrl }}?action=accept" class="button" style="background-color: #28a745; margin-right: 10px;">Accept Invitation</a>
                <a href="{{ $rejectUrl }}?action=reject" class="button" style="background-color: #dc3545;">Reject Invitation</a>
            </div>
            
            <p style="margin-top: 20px; font-size: 12px; color: #666;">
                If you cannot click the button, copy and paste these URLs into your browser:<br>
                Accept: <a href="{{ $acceptUrl }}?action=accept">{{ $acceptUrl }}?action=accept</a><br>
                Reject: <a href="{{ $rejectUrl }}?action=reject">{{ $rejectUrl }}?action=reject</a>
            </p>
        </div>

        <div class="footer">
            <p>This invitation was sent from {{ $appName }}.</p>
            <p>If you did not expect this invitation, you can safely ignore this email.</p>
        </div>
    </div>
</body>
</html>

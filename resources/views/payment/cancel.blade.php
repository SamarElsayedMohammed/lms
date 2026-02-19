<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled - {{ config('app.name') }}</title>
</head>
<body>
    <div class="cancel-container">
        <div class="cancel-icon">❌</div>
        <h1 class="cancel-title">Payment Cancelled</h1>
        <p class="cancel-message">Your payment was not completed. No charges have been made to your account.</p>
        
        <div class="error-details">
            <h3 style="margin-top: 0; color: #333;">Details</h3>
            <p class="error-message">{{ $error }}</p>
        </div>

        <div class="action-buttons">
            <a href="{{ url('/') }}" class="btn btn-primary">Try Again</a>
            <a href="{{ url('/courses') }}" class="btn btn-secondary">Browse Courses</a>
        </div>
    </div>
</body>
</html>

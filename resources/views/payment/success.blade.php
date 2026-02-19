<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful - {{ config('app.name') }}</title>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">✅</div>
        <h1 class="success-title">Payment Successful!</h1>
        <p class="success-message">Thank you for your purchase. Your payment has been processed successfully.</p>
        
        <div class="payment-details">
            <h3 style="margin-top: 0; color: #333;">Payment Details</h3>
            <div class="detail-row">
                <span class="detail-label">Order Number:</span>
                <span class="detail-value">{{ $orderNumber }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Payment ID:</span>
                <span class="detail-value">{{ $paymentId }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Status:</span>
                <span class="detail-value" style="color: #4CAF50; font-weight: bold;">Completed</span>
            </div>
        </div>

        <div class="action-buttons">
            <a href="{{ url('/') }}" class="btn btn-primary">Go to Dashboard</a>
            <a href="{{ url('/my-courses') }}" class="btn btn-secondary">View My Courses</a>
        </div>
    </div>
</body>
</html>

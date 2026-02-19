<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .alert { padding: 15px; border-radius: 5px; margin: 15px 0; }
        .alert-warning { background: #fff3cd; border: 1px solid #ffc107; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: #fff; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>{{ __('Subscription Expiry Reminder') }}</h2>
        <p>{{ __('Hello') }} {{ $subscription->user->name }},</p>
        @if($daysUntilExpiry === 1)
            <div class="alert alert-warning">{{ __('Your subscription to :plan expires in 24 hours.', ['plan' => $subscription->plan->name]) }}</div>
        @else
            <div class="alert alert-warning">{{ __('Your subscription to :plan expires in :days days.', ['plan' => $subscription->plan->name, 'days' => $daysUntilExpiry]) }}</div>
        @endif
        <p>{{ __('Expiry date') }}: {{ $subscription->ends_at?->format('Y-m-d') }}</p>
        <p>{{ __('Please renew your subscription to continue enjoying full access.') }}</p>
        <p><a href="{{ config('app.url') }}" class="btn">{{ __('Renew Now') }}</a></p>
        <p>{{ __('Thank you for being a subscriber!') }}</p>
    </div>
</body>
</html>

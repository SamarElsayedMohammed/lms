<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $found ? __('Certificate Verification') : __('Certificate Not Found') }} — {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f8f9fa; }
        .verify-card { max-width: 500px; }
        .badge-valid { font-size: 1rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card verify-card shadow">
            <div class="card-body p-4">
                @if($found)
                    <div class="text-center mb-3">
                        <span class="badge bg-success badge-valid px-3 py-2">
                            ✓ {{ __('Valid Certificate') }}
                        </span>
                    </div>
                    <h4 class="card-title text-center mb-4">{{ __('Certificate Verification') }}</h4>
                    <table class="table table-borderless mb-0">
                        <tr>
                            <th class="text-muted" style="width: 40%;">{{ __('Certificate Number') }}</th>
                            <td>{{ $certificate->certificate_number }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">{{ __('Student Name') }}</th>
                            <td>{{ $certificate->user->name ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">{{ __('Course') }}</th>
                            <td>{{ $certificate->course->title ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">{{ __('Completion Date') }}</th>
                            <td>{{ $certificate->issued_date ? \Carbon\Carbon::parse($certificate->issued_date)->format('F d, Y') : '-' }}</td>
                        </tr>
                    </table>
                    <p class="text-muted small mt-3 mb-0 text-center">
                        {{ __('This certificate was issued by') }} {{ config('app.name') }}.
                    </p>
                @else
                    <div class="text-center mb-3">
                        <span class="badge bg-danger badge-valid px-3 py-2">
                            ✗ {{ __('Certificate Not Found') }}
                        </span>
                    </div>
                    <h4 class="card-title text-center mb-4">{{ __('Certificate Verification') }}</h4>
                    <p class="text-center text-muted mb-0">
                        {{ __('No certificate was found with this number. Please check the certificate number and try again.') }}
                    </p>
                @endif
            </div>
        </div>
    </div>
</body>
</html>

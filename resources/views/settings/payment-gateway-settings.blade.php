@extends('layouts.app')

@section('title')
    {{ __('Payment Gateway Settings') }}
@endsection
@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
    </div> @endsection

@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <form action="{{ route('settings.payment-gateway.update') }}" method="POST" enctype="multipart/form-data" class="create-form" data-success-function="formSuccessFunction">
                    <div class="card">
                        <div class="card-header">
                            <div class="divider">
                                <div class="divider-text">
                                    <h4 class="card-title">{{ __('Razorpay Gateway Settings') }}</h4>
                                </div>
                            </div>
                        </div>
                        <div class="card-body mt-4">
                            <div class="row">
                                {{-- Razorpay API Key Settings --}}
                                <div class="col-lg-6">
                                    <div class="form-group mandatory">
                                        <label for="razorpay-api-key">{{ __('API Key') }}</label>
                                        <input type="text" class="form-control" id="razorpay-api-key" name="razorpay_api_key" value="{{ $razorpayPaymentGateway['razorpay_api_key'] ?? '' }}" placeholder="{{ __('Enter API Key') }}">
                                    </div>
                                </div>

                                {{-- Razorpay Secret Key Settings --}}
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="razorpay-secret-key">{{ __('Secret Key') }}</label>
                                        <input type="text" class="form-control" id="razorpay-secret-key" name="razorpay_secret_key" value="{{ $razorpayPaymentGateway['razorpay_secret_key'] ?? '' }}" placeholder="{{ __('Enter Secret Key') }}">
                                    </div>
                                </div>

                                {{-- Razorpay Webhook URL Settings --}}
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="razorpay-webhook-url">{{ __('Webhook URL') }}</label>
                                        <input type="text" class="form-control" id="razorpay-webhook-url" name="razorpay_webhook_url" value="{{ !empty($razorpayPaymentGateway['razorpay_webhook_url']) ? $razorpayPaymentGateway['razorpay_webhook_url'] : url('/webhook/razorpay') }}" placeholder="{{ __('Enter Webhook URL') }}" readonly>
                                    </div>
                                </div>

                                {{-- Razorpay Webhook Secret Key Settings --}}
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="razorpay-webhook-secret-key">{{ __('Webhook Secret Key') }}</label>
                                        <input type="text" class="form-control" id="razorpay-webhook-secret-key" name="razorpay_webhook_secret_key" value="{{ $razorpayPaymentGateway['razorpay_webhook_secret_key'] ?? '' }}" placeholder="{{ __('Enter Webhook Secret Key') }}">
                                    </div>
                                </div>

                                {{-- Status --}}
                                <div class="form-group col-lg-6">
                                    <div class="control-label">{{ __('Status') }}</div>
                                    <div class="custom-switches-stacked mt-2">
                                        <label class="custom-switch">
                                            <input type="checkbox" name="razorpay_status" value="1" class="custom-switch-input" id="razorpay-status" {{ !empty($razorpayPaymentGateway['razorpay_status']) && ($razorpayPaymentGateway['razorpay_status'] == 1 || $razorpayPaymentGateway['razorpay_status'] == '1') ? 'checked' : '' }}>
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description">{{ __('Enable') }}</span>
                                        </label>
                                    </div>
                                </div>



                                
                            </div>
                           
                        </div>
                    </div>
                    <div class="card mt-4">
    <div class="card-header">
        <div class="divider">
            <div class="divider-text">
                <h4 class="card-title">{{ __('Stripe Payment Gateway') }}</h4>
            </div>
        </div>
    </div>

    <div class="card-body mt-4">
        <div class="row">
            {{-- Stripe Publishable Key --}}
            <div class="col-lg-6">
                <div class="form-group mandatory">
                    <label for="stripe-publishable-key">{{ __('Publishable Key') }}</label>
                    <input type="text" class="form-control" id="stripe-publishable-key"
                           name="stripe_publishable_key"
                           value="{{ $stripePaymentGateway['stripe_publishable_key'] ?? '' }}"
                           placeholder="{{ __('Enter Publishable Key') }}">
                </div>
            </div>

            {{-- Stripe Secret Key --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="stripe-secret-key">{{ __('Secret Key') }}</label>
                    <input type="text" class="form-control" id="stripe-secret-key"
                           name="stripe_secret_key"
                           value="{{ $stripePaymentGateway['stripe_secret_key'] ?? '' }}"
                           placeholder="{{ __('Enter Secret Key') }}">
                </div>
            </div>

            {{-- Stripe Webhook URL (readonly) --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="stripe-webhook-url">{{ __('Webhook URL') }}</label>
                    <input type="text" class="form-control" id="stripe-webhook-url"
                           value="{{ url('/webhook/stripe') }}"
                           readonly>
                    <small class="form-text text-muted">
                        {{ __('Copy this URL to your Stripe webhook settings') }}
                    </small>
                </div>
            </div>

            {{-- Stripe Webhook Secret --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="stripe-webhook-secret">{{ __('Webhook Secret') }}</label>
                    <input type="text" class="form-control" id="stripe-webhook-secret"
                           name="stripe_webhook_secret"
                           value="{{ $stripePaymentGateway['stripe_webhook_secret'] ?? '' }}"
                           placeholder="{{ __('Enter Webhook Secret') }}">
                    <small class="form-text text-muted">
                        {{ __('Optional: For webhook signature verification') }}
                    </small>
                </div>
            </div>

            {{-- Default Currency --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="stripe-currency">{{ __('Default Currency') }}</label>
                    @php
                        $currencies = [
                            'USD' => 'USD — United States Dollar',
                            'EUR' => 'EUR — Euro',
                            'GBP' => 'GBP — British Pound',
                            'INR' => 'INR — Indian Rupee',
                            'AED' => 'AED — UAE Dirham',
                            'SAR' => 'SAR — Saudi Riyal',
                            'AUD' => 'AUD — Australian Dollar',
                            'CAD' => 'CAD — Canadian Dollar',
                            'SGD' => 'SGD — Singapore Dollar',
                            'JPY' => 'JPY — Japanese Yen (zero-decimal)',
                        ];
                        $selectedCurrency = $stripePaymentGateway['stripe_currency'] ?? 'USD';
                    @endphp
                    <select class="form-control" id="stripe-currency" name="stripe_currency">
                        @foreach($currencies as $code => $label)
                            <option value="{{ $code }}" {{ $selectedCurrency === $code ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">
                        {{ __('Make sure your Stripe account supports this currency and payment method in your region.') }}
                    </small>
                </div>
            </div>

            {{-- Status --}}
            <div class="form-group col-lg-6">
                <div class="control-label">{{ __('Status') }}</div>
                <div class="custom-switches-stacked mt-2">
                    <label class="custom-switch">
                        <input type="checkbox" name="stripe_status" value="1"
                               class="custom-switch-input" id="stripe-status"
                               {{ !empty($stripePaymentGateway['stripe_status']) && ($stripePaymentGateway['stripe_status'] == 1 || $stripePaymentGateway['stripe_status'] == '1') ? 'checked' : '' }}>
                        <span class="custom-switch-indicator"></span>
                        <span class="custom-switch-description">{{ __('Enable') }}</span>
                    </label>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header">
        <div class="divider">
            <div class="divider-text">
                <h4 class="card-title">{{ __('Flutterwave Settings') }}</h4>
            </div>
        </div>
    </div>

    <div class="card-body mt-4">
        <div class="row">
            {{-- Flutterwave Public Key --}}
            <div class="col-lg-6">
                <div class="form-group mandatory">
                    <label for="flutterwave-public-key">{{ __('Public Key') }}</label>
                    <input type="text" class="form-control" id="flutterwave-public-key"
                           name="flutterwave_public_key"
                           value="{{ $flutterwavePaymentGateway['flutterwave_public_key'] ?? '' }}"
                           placeholder="{{ __('Enter Public Key') }}">
                </div>
            </div>

            {{-- Flutterwave Secret Key --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="flutterwave-secret-key">{{ __('Secret Key') }}</label>
                    <input type="text" class="form-control" id="flutterwave-secret-key"
                           name="flutterwave_secret_key"
                           value="{{ $flutterwavePaymentGateway['flutterwave_secret_key'] ?? '' }}"
                           placeholder="{{ __('Enter Secret Key') }}">
                </div>
            </div>

            {{-- Flutterwave Webhook URL (readonly) --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="flutterwave-webhook-url">{{ __('Webhook URL') }}</label>
                    <input type="text" class="form-control" id="flutterwave-webhook-url"
                           value="{{ url('/webhook/flutterwave') }}"
                           readonly>
                    <small class="form-text text-muted">
                        {{ __('Copy this URL to your Flutterwave webhook settings') }}
                    </small>
                </div>
            </div>

            {{-- Flutterwave Webhook Secret --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="flutterwave-webhook-secret">{{ __('Webhook Secret') }}</label>
                    <input type="text" class="form-control" id="flutterwave-webhook-secret"
                           name="flutterwave_webhook_secret"
                           value="{{ $flutterwavePaymentGateway['flutterwave_webhook_secret'] ?? '' }}"
                           placeholder="{{ __('Enter Webhook Secret') }}">
                    <small class="form-text text-muted">
                        {{ __('Optional: For webhook signature verification') }}
                    </small>
                </div>
            </div>

            {{-- Flutterwave Encryption Key --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="flutterwave-encryption-key">{{ __('Encryption Key') }}</label>
                    <input type="text" class="form-control" id="flutterwave-encryption-key"
                           name="flutterwave_encryption_key"
                           value="{{ $flutterwavePaymentGateway['flutterwave_encryption_key'] ?? '' }}"
                           placeholder="{{ __('Enter Encryption Key') }}">
                    <small class="form-text text-muted">
                        {{ __('Required for payment processing') }}
                    </small>
                </div>
            </div>

            {{-- Default Currency --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="flutterwave-currency">{{ __('Default Currency') }}</label>
                    @php
                        $flutterwaveCurrencies = [
                            'NGN' => 'NGN — Nigerian Naira',
                            'USD' => 'USD — United States Dollar',
                            'EUR' => 'EUR — Euro',
                            'GBP' => 'GBP — British Pound',
                            'KES' => 'KES — Kenyan Shilling',
                            'GHS' => 'GHS — Ghanaian Cedi',
                            'ZAR' => 'ZAR — South African Rand',
                            'EGP' => 'EGP — Egyptian Pound',
                            'UGX' => 'UGX — Ugandan Shilling',
                            'TZS' => 'TZS — Tanzanian Shilling',
                        ];
                        $selectedFlutterwaveCurrency = $flutterwavePaymentGateway['flutterwave_currency'] ?? 'NGN';
                    @endphp
                    <select class="form-control" id="flutterwave-currency" name="flutterwave_currency">
                        @foreach($flutterwaveCurrencies as $code => $label)
                            <option value="{{ $code }}" {{ $selectedFlutterwaveCurrency === $code ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">
                        {{ __('Make sure your Flutterwave account supports this currency.') }}
                    </small>
                </div>
            </div>

            {{-- Status --}}
            <div class="form-group col-lg-6">
                <div class="control-label">{{ __('Status') }}</div>
                <div class="custom-switches-stacked mt-2">
                    <label class="custom-switch">
                        <input type="checkbox" name="flutterwave_status" value="1"
                               class="custom-switch-input" id="flutterwave-status"
                               {{ !empty($flutterwavePaymentGateway['flutterwave_status']) && ($flutterwavePaymentGateway['flutterwave_status'] == 1 || $flutterwavePaymentGateway['flutterwave_status'] == '1') ? 'checked' : '' }}>
                        <span class="custom-switch-indicator"></span>
                        <span class="custom-switch-description">{{ __('Enable') }}</span>
                    </label>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header">
        <div class="divider">
            <div class="divider-text">
                <h4 class="card-title">{{ __('Kashier Payment Gateway') }}</h4>
            </div>
        </div>
    </div>
    <div class="card-body mt-4">
        <div class="row">
            {{-- Kashier Merchant ID --}}
            <div class="col-lg-6">
                <div class="form-group mandatory">
                    <label for="kashier-merchant-id">{{ __('Merchant ID') }}</label>
                    <input type="text" class="form-control" id="kashier-merchant-id"
                           name="kashier_merchant_id"
                           value="{{ $kashierPaymentGateway['kashier_merchant_id'] ?? '' }}"
                           placeholder="{{ __('Enter Merchant ID') }}">
                </div>
            </div>

            {{-- Kashier API Key --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="kashier-api-key">{{ __('API Key') }}</label>
                    <input type="text" class="form-control" id="kashier-api-key"
                           name="kashier_api_key"
                           value="{{ $kashierPaymentGateway['kashier_api_key'] ?? '' }}"
                           placeholder="{{ __('Enter API Key') }}">
                </div>
            </div>

            {{-- Kashier Webhook URL (readonly) --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="kashier-webhook-url">{{ __('Webhook URL') }}</label>
                    <input type="text" class="form-control" id="kashier-webhook-url"
                           value="{{ url('/webhooks/kashier') }}"
                           readonly>
                    <small class="form-text text-muted">
                        {{ __('Copy this URL to your Kashier webhook settings') }}
                    </small>
                </div>
            </div>

            {{-- Kashier Webhook Secret --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="kashier-webhook-secret">{{ __('Webhook Secret') }}</label>
                    <input type="text" class="form-control" id="kashier-webhook-secret"
                           name="kashier_webhook_secret"
                           value="{{ $kashierPaymentGateway['kashier_webhook_secret'] ?? '' }}"
                           placeholder="{{ __('Enter Webhook Secret') }}">
                    <small class="form-text text-muted">
                        {{ __('For webhook signature verification') }}
                    </small>
                </div>
            </div>

            {{-- Kashier Mode --}}
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="kashier-mode">{{ __('Mode') }}</label>
                    @php
                        $selectedKashierMode = $kashierPaymentGateway['kashier_mode'] ?? 'test';
                    @endphp
                    <select class="form-control" id="kashier-mode" name="kashier_mode">
                        <option value="test" {{ $selectedKashierMode === 'test' ? 'selected' : '' }}>{{ __('Test') }}</option>
                        <option value="live" {{ $selectedKashierMode === 'live' ? 'selected' : '' }}>{{ __('Live') }}</option>
                    </select>
                    <small class="form-text text-muted">
                        {{ __('Use Test for sandbox, Live for production') }}
                    </small>
                </div>
            </div>
        </div>
        <div class="row mt-4">
            {{-- Save Button --}}
            <div class="col-12 text-right">
                <button class="btn btn-primary" id="save-btn">{{ __('Update') }}</button>
            </div>
        </div>
    </div>
</div>
                </form>
            </div>
        </div>
    </div>
@endsection

    @push('scripts')
<script>
    $(document).ready(function () {
        @if(isset($settings['maintaince_mode']) && $settings['maintaince_mode'] == 1)
            $('#maintaince-mode').prop('checked', true).trigger('change');
        @endif

        @if(isset($settings['force_update']) && $settings['force_update'] == 1)
            $('#force-update').prop('checked', true).trigger('change');
        @endif
    });

    function formSuccessFunction() {
        location.reload();
    }
</script>
@endpush

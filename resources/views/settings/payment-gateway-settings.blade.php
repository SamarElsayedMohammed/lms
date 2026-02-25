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
            <form action="{{ route('settings.payment-gateway.update') }}" method="POST" enctype="multipart/form-data"
                class="create-form" data-success-function="formSuccessFunction">

                <div class="card">
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
                                    <input type="text" class="form-control" id="kashier-api-key" name="kashier_api_key"
                                        value="{{ $kashierPaymentGateway['kashier_api_key'] ?? '' }}"
                                        placeholder="{{ __('Enter API Key') }}">
                                </div>
                            </div>

                            {{-- Kashier Webhook URL (readonly) --}}
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label for="kashier-webhook-url">{{ __('Webhook URL') }}</label>
                                    <input type="text" class="form-control" id="kashier-webhook-url"
                                        value="{{ url('/webhooks/kashier') }}" readonly>
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
                                        <option value="test" {{ $selectedKashierMode==='test' ? 'selected' : '' }}>{{
                                            __('Test') }}</option>
                                        <option value="live" {{ $selectedKashierMode==='live' ? 'selected' : '' }}>{{
                                            __('Live') }}</option>
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
        @if (isset($settings['maintaince_mode']) && $settings['maintaince_mode'] == 1)
            $('#maintaince-mode').prop('checked', true).trigger('change');
        @endif

        @if (isset($settings['force_update']) && $settings['force_update'] == 1)
            $('#force-update').prop('checked', true).trigger('change');
        @endif
    });

    function formSuccessFunction() {
        location.reload();
    }
</script>
@endpush
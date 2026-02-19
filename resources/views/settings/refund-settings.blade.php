@extends('layouts.app')
@section('title')
    {{ __('Refund Settings') }}
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
                <div class="card">
                    <div class="card-body">
                        <form action="{{ route('settings.refund.update') }}" method="POST" enctype="multipart/form-data" class="create-form" data-success-function="formSuccessFunction" id="refundForm"> @csrf <div class="row">
                                <!-- Refund Status -->
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group mandatory">
                                        <label for="refund_enabled" class="form-label">{{ __('Enable Refunds') }}</label>
                                        <select name="refund_enabled" id="refund_enabled" class="form-control" required>
                                            <option value="1" {{ isset($settings['refund_enabled']) && $settings['refund_enabled'] == 1 ? 'selected' : '' }}> {{ __('Enabled') }} </option>
                                            <option value="0" {{ isset($settings['refund_enabled']) && $settings['refund_enabled'] == 0 ? 'selected' : '' }}> {{ __('Disabled') }} </option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Refund Days -->
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group mandatory">
                                        <label for="refund_period_days" class="form-label">{{ __('Refund Period (Days)') }}</label>
                                        <input type="number" name="refund_period_days" class="form-control" id="refund_period_days" value="{{ $settings['refund_period_days'] ?? 7 }}" min="0" placeholder="{{ __('Number of days allowed for refund') }}" required>
                                        <small class="form-text text-muted"> {{ __('Number of days after purchase when refunds are allowed') }} </small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <!-- Refund Policy -->
                                <div class="col-12">
                                    <div class="form-group mandatory">
                                        <label for="refund_policy" class="form-label">{{ __('Refund Policy') }}</label>
                                        <textarea name="refund_policy" class="form-control tinymce-editor" required>{{ $settings['refund_policy'] ?? '' }}</textarea>
                                        <small class="form-text text-muted"> {{ __('Describe your refund policy terms and conditions') }} </small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary me-1 mb-1" id="submitBtn"> {{ __('Save Settings') }} </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div> @endsection

@push('scripts')
<script>
        function formSuccessFunction() {
            window.location.reload();
        }
    </script>
@endpush

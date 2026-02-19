@extends('layouts.app')

@section('title')
    {{ __('Edit Subscription Plan') }}: {{ $plan->name }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
@endsection

@section('main')
<div class="section">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('subscription-plans.update', $plan) }}">
                        @csrf
                        @method('PUT')

                        <div class="row">
                            <div class="form-group col-md-6">
                                <label>{{ __('Plan Name') }} <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" value="{{ old('name', $plan->name) }}" required>
                                @error('name')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-6">
                                <label>{{ __('Billing Cycle') }} <span class="text-danger">*</span></label>
                                <select name="billing_cycle" id="billing_cycle" class="form-control" required>
                                    @foreach($billingCycles as $value => $label)
                                        <option value="{{ $value }}" {{ old('billing_cycle', $plan->billing_cycle) == $value ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('billing_cycle')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-6" id="duration_days_group" style="display:none;">
                                <label>{{ __('Duration (Days)') }} <span class="text-danger">*</span></label>
                                <input type="number" name="duration_days" class="form-control" value="{{ old('duration_days', $plan->duration_days) }}" min="1">
                                @error('duration_days')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-6">
                                <label>{{ __('Price') }} ({{ \App\Services\CachingService::getSystemSettings('currency_symbol') ?: 'EGP' }}) <span class="text-danger">*</span></label>
                                <input type="number" name="price" class="form-control" value="{{ old('price', $plan->price) }}" min="0" step="0.01" required>
                                @error('price')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-6">
                                <label>{{ __('Commission Rate') }} (%)</label>
                                <input type="number" name="commission_rate" class="form-control" value="{{ old('commission_rate', $plan->commission_rate) }}" min="0" max="100" step="0.01">
                                @error('commission_rate')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-6">
                                <label>{{ __('Sort Order') }}</label>
                                <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $plan->sort_order) }}" min="0">
                                @error('sort_order')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-12">
                                <label>{{ __('Description') }}</label>
                                <textarea name="description" class="form-control" rows="3">{{ old('description', $plan->description) }}</textarea>
                                @error('description')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-12">
                                <label>{{ __('Features') }}</label>
                                <div id="features_container">
                                    @php $features = old('features', $plan->features ?? []); @endphp
                                    @if(!empty($features))
                                        @foreach((array)$features as $f)
                                            @if($f)
                                            <div class="input-group mb-2"><input type="text" name="features[]" class="form-control" value="{{ $f }}"><div class="input-group-append"><button type="button" class="btn btn-danger remove-feature">×</button></div></div>
                                            @endif
                                        @endforeach
                                    @endif
                                    <div class="input-group mb-2"><input type="text" name="features[]" class="form-control" placeholder="{{ __('Feature') }}"><div class="input-group-append"><button type="button" class="btn btn-danger remove-feature">×</button></div></div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-1" id="add_feature"><i class="fas fa-plus"></i> {{ __('Add Feature') }}</button>
                            </div>

                            <div class="form-group col-md-6">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" name="is_active" value="1" class="custom-control-input" id="is_active" {{ old('is_active', $plan->is_active) ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="is_active">{{ __('Active') }}</label>
                                </div>
                            </div>
                        </div>

                        @if(!empty($supportedCurrencies))
                        <hr class="my-4">
                        <h5 class="mb-3">{{ __('Country Prices') }}</h5>
                        <p class="text-muted small">{{ __('Set prices per country. Leave empty to use base EGP price.') }}</p>
                        <div class="table-responsive mb-3">
                            <table class="table table-bordered" id="country-prices-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('Country') }}</th>
                                        <th>{{ __('Currency') }}</th>
                                        <th>{{ __('Price') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($supportedCurrencies as $currency)
                                        @php
                                            $existing = $countryPrices[$currency->country_code] ?? null;
                                            $priceVal = $existing ? (float) $existing->price : '';
                                        @endphp
                                        <tr>
                                            <td>{{ $currency->country_name }} ({{ $currency->country_code }})</td>
                                            <td>{{ $currency->currency_code }} ({{ $currency->currency_symbol }})</td>
                                            <td>
                                                <input type="number" class="form-control form-control-sm country-price-input"
                                                    data-country="{{ $currency->country_code }}"
                                                    value="{{ $priceVal }}"
                                                    min="0" step="0.01" placeholder="{{ __('Base price') }}">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <button type="button" class="btn btn-outline-primary" id="save-country-prices">
                            <i class="fas fa-save"></i> {{ __('Save Country Prices') }}
                        </button>
                        <span id="country-prices-status" class="ml-2 text-muted small"></span>
                        @endif

                        <div class="form-group mt-4">
                            <button type="submit" class="btn btn-primary">{{ __('Update') }}</button>
                            <a href="{{ route('subscription-plans.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                            <a href="{{ route('subscription-plans.show', $plan) }}" class="btn btn-info">{{ __('View') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function() {
    function toggleDurationDays() {
        const cycle = $('#billing_cycle').val();
        $('#duration_days_group').toggle(cycle === 'custom');
        if (cycle !== 'custom') {
            $('input[name="duration_days"]').val('');
        }
    }
    $('#billing_cycle').on('change', toggleDurationDays);
    toggleDurationDays();

    $('#add_feature').on('click', function() {
        $('#features_container').append(
            '<div class="input-group mb-2"><input type="text" name="features[]" class="form-control" placeholder="{{ __("Feature") }}"><div class="input-group-append"><button type="button" class="btn btn-danger remove-feature">×</button></div></div>'
        );
    });
    $(document).on('click', '.remove-feature', function() {
        $(this).closest('.input-group').remove();
    });

    $('#save-country-prices').on('click', function() {
        const btn = $(this);
        const status = $('#country-prices-status');
        const countryPrices = {};
        $('.country-price-input').each(function() {
            const country = $(this).data('country');
            const val = $(this).val();
            countryPrices[country] = val === '' ? '' : val;
        });
        btn.prop('disabled', true);
        status.text('{{ __("Saving...") }}');
        $.ajax({
            url: '{{ route("subscription-plans.country-prices", $plan) }}',
            method: 'PUT',
            data: {
                _token: '{{ csrf_token() }}',
                country_prices: countryPrices
            },
            success: function() {
                status.text('{{ __("Saved successfully.") }}').removeClass('text-danger').addClass('text-success');
            },
            error: function(xhr) {
                status.text(xhr.responseJSON?.message || '{{ __("Error saving.") }}').removeClass('text-success').addClass('text-danger');
            },
            complete: function() {
                btn.prop('disabled', false);
            }
        });
    });
});
</script>
@endpush
@endsection

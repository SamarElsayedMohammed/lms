@extends('layouts.app')

@section('title')
    {{ __('Create Subscription Plan') }}
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
                    <form method="POST" action="{{ route('subscription-plans.store') }}">
                        @csrf

                        <div class="row">
                            <div class="form-group col-md-6">
                                <label>{{ __('Plan Name') }} <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
                                @error('name')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-6">
                                <label>{{ __('Billing Cycle') }} <span class="text-danger">*</span></label>
                                <select name="billing_cycle" id="billing_cycle" class="form-control" required>
                                    @foreach($billingCycles as $value => $label)
                                        <option value="{{ $value }}" {{ old('billing_cycle') == $value ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('billing_cycle')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-6" id="duration_days_group" style="display:none;">
                                <label>{{ __('Duration (Days)') }} <span class="text-danger">*</span></label>
                                <input type="number" name="duration_days" class="form-control" value="{{ old('duration_days') }}" min="1">
                                @error('duration_days')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-6">
                                <label>{{ __('Price') }} ({{ \App\Services\CachingService::getSystemSettings('currency_symbol') ?: 'EGP' }}) <span class="text-danger">*</span></label>
                                <input type="number" name="price" class="form-control" value="{{ old('price', 0) }}" min="0" step="0.01" required>
                                @error('price')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-6">
                                <label>{{ __('Commission Rate') }} (%)</label>
                                <input type="number" name="commission_rate" class="form-control" value="{{ old('commission_rate', 0) }}" min="0" max="100" step="0.01">
                                @error('commission_rate')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-6">
                                <label>{{ __('Sort Order') }}</label>
                                <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', 0) }}" min="0">
                                @error('sort_order')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-12">
                                <label>{{ __('Description') }}</label>
                                <textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea>
                                @error('description')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-12">
                                <label>{{ __('Features') }}</label>
                                <div id="features_container">
                                    @if(old('features'))
                                        @foreach(old('features') as $f)
                                            <div class="input-group mb-2"><input type="text" name="features[]" class="form-control" value="{{ $f }}"><div class="input-group-append"><button type="button" class="btn btn-danger remove-feature">×</button></div></div>
                                        @endforeach
                                    @endif
                                    <div class="input-group mb-2"><input type="text" name="features[]" class="form-control" placeholder="{{ __('Feature') }}"><div class="input-group-append"><button type="button" class="btn btn-danger remove-feature">×</button></div></div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-1" id="add_feature"><i class="fas fa-plus"></i> {{ __('Add Feature') }}</button>
                            </div>

                            <div class="form-group col-md-6">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" name="is_active" value="1" class="custom-control-input" id="is_active" {{ old('is_active', true) ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="is_active">{{ __('Active') }}</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">{{ __('Create') }}</button>
                            <a href="{{ route('subscription-plans.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
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
});
</script>
@endpush
@endsection

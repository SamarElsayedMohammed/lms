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
                    <form method="POST" action="{{ route('subscription-plans.store') }}" id="plan-form">
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
                                    <option value="{{ $value }}" {{ old('billing_cycle')==$value ? 'selected' : '' }}>{{
                                        $label }}</option>
                                    @endforeach
                                </select>
                                @error('billing_cycle')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-6" id="duration_days_group" style="display:none;">
                                <label>{{ __('Duration (Days)') }} <span class="text-danger">*</span></label>
                                <input type="number" name="duration_days" class="form-control"
                                    value="{{ old('duration_days') }}" min="1">
                                @error('duration_days')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>


                            <div class="form-group col-md-6">
                                <label>{{ __('Commission Rate') }} (%)</label>
                                <input type="number" name="commission_rate" class="form-control"
                                    value="{{ old('commission_rate', 0) }}" min="0" max="100" step="0.01">
                                @error('commission_rate')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-6">
                                <label>{{ __('Sort Order') }}</label>
                                <input type="number" name="sort_order" class="form-control"
                                    value="{{ old('sort_order', 0) }}" min="0">
                                @error('sort_order')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-12">
                                <label>{{ __('Description') }}</label>
                                <textarea name="description" class="form-control"
                                    rows="3">{{ old('description') }}</textarea>
                                @error('description')<span class="text-danger">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group col-md-12">
                                <label>{{ __('Features') }}</label>
                                <div id="features_container">
                                    @if(old('features'))
                                    @foreach(old('features') as $f)
                                    <div class="input-group mb-2"><input type="text" name="features[]"
                                            class="form-control" value="{{ $f }}">
                                        <div class="input-group-append"><button type="button"
                                                class="btn btn-danger remove-feature">×</button></div>
                                    </div>
                                    @endforeach
                                    @endif
                                    <div class="input-group mb-2"><input type="text" name="features[]"
                                            class="form-control" placeholder="{{ __('Feature') }}">
                                        <div class="input-group-append"><button type="button"
                                                class="btn btn-danger remove-feature">×</button></div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-1" id="add_feature"><i
                                        class="fas fa-plus"></i> {{ __('Add Feature') }}</button>
                            </div>

                            <div class="form-group col-md-6">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" name="is_active" value="1" class="custom-control-input"
                                        id="is_active" {{ old('is_active', true) ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="is_active">{{ __('Active') }}</label>
                                </div>
                            </div>
                        </div>

                        {{-- Country Prices Section --}}
                        <hr class="my-4">
                        <h5 class="mb-3">{{ __('Country Prices') }} <span class="text-danger">*</span></h5>
                        <p class="text-muted small">{{ __('اختر الدول وحدد السعر الأساسي وسعر العرض لكل دولة. يجب اختيار
                            دولة واحدة على الأقل.') }}</p>
                        @error('countries')<div class="alert alert-danger">{{ $message }}</div>@enderror

                        <div id="country-prices-container">
                            @if(old('countries'))
                            @foreach(old('countries') as $index => $entry)
                            <div class="country-row card card-body mb-2 p-3" data-index="{{ $index }}">
                                <div class="row align-items-end">
                                    <div class="form-group col-md-4 mb-1">
                                        <label>{{ __('Country') }} <span class="text-danger">*</span></label>
                                        <select name="countries[{{ $index }}][country_id]"
                                            class="form-control country-select" required>
                                            <option value="">-- {{ __('اختر الدولة') }} --</option>
                                            @foreach($countries as $country)
                                            <option value="{{ $country->id }}" {{ ($entry['country_id'] ?? ''
                                                )==$country->id ? 'selected' : '' }}>
                                                {{ $country->name_ar }} ({{ $country->name_en }})
                                            </option>
                                            @endforeach
                                        </select>
                                        @error("countries.{$index}.country_id")<span class="text-danger small">{{
                                            $message }}</span>@enderror
                                    </div>
                                    <div class="form-group col-md-3 mb-1">
                                        <label>{{ __('Base Price') }} <span class="text-danger">*</span></label>
                                        <input type="number" name="countries[{{ $index }}][price]" class="form-control"
                                            value="{{ $entry['price'] ?? '' }}" min="0" step="0.01" required>
                                        @error("countries.{$index}.price")<span class="text-danger small">{{ $message
                                            }}</span>@enderror
                                    </div>
                                    <div class="form-group col-md-3 mb-1">
                                        <label>{{ __('Offer Price') }}</label>
                                        <input type="number" name="countries[{{ $index }}][offer_price]"
                                            class="form-control" value="{{ $entry['offer_price'] ?? '' }}" min="0"
                                            step="0.01">
                                        @error("countries.{$index}.offer_price")<span class="text-danger small">{{
                                            $message }}</span>@enderror
                                    </div>
                                    <div class="form-group col-md-2 mb-1">
                                        <button type="button" class="btn btn-danger btn-sm remove-country-row"><i
                                                class="fas fa-trash"></i> {{ __('حذف') }}</button>
                                    </div>
                                </div>
                            </div>
                            @endforeach
                            @endif
                        </div>

                        <button type="button" class="btn btn-outline-success mb-3" id="add-country-row">
                            <i class="fas fa-plus"></i> {{ __('إضافة دولة') }}
                        </button>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">{{ __('Create') }}</button>
                            <a href="{{ route('subscription-plans.index') }}" class="btn btn-secondary">{{ __('Cancel')
                                }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    $(function () {
        var countryIndex = {{ old('countries') ? count(old('countries')) : 0
    }};
    var countriesJson = @json($countries);

    function toggleDurationDays() {
        const cycle = $('#billing_cycle').val();
        $('#duration_days_group').toggle(cycle === 'custom');
        if (cycle !== 'custom') {
            $('input[name="duration_days"]').val('');
        }
    }
    $('#billing_cycle').on('change', toggleDurationDays);
    toggleDurationDays();

    $('#add_feature').on('click', function () {
        $('#features_container').append(
            '<div class="input-group mb-2"><input type="text" name="features[]" class="form-control" placeholder="{{ __("Feature") }}"><div class="input-group-append"><button type="button" class="btn btn-danger remove-feature">×</button></div></div>'
        );
    });
    $(document).on('click', '.remove-feature', function () {
        $(this).closest('.input-group').remove();
    });

    // Build country option HTML
    function buildCountryOptions(selectedId) {
        var html = '<option value="">-- {{ __("اختر الدولة") }} --</option>';
        $.each(countriesJson, function (i, c) {
            var selected = (selectedId && selectedId == c.id) ? 'selected' : '';
            html += '<option value="' + c.id + '" ' + selected + '>' + c.name_ar + ' (' + c.name_en + ')</option>';
        });
        return html;
    }

    function addCountryRow(selectedId, price, offerPrice) {
        var idx = countryIndex++;
        var html = '<div class="country-row card card-body mb-2 p-3" data-index="' + idx + '">' +
            '<div class="row align-items-end">' +
            '<div class="form-group col-md-4 mb-1">' +
            '<label>{{ __("Country") }} <span class="text-danger">*</span></label>' +
            '<select name="countries[' + idx + '][country_id]" class="form-control country-select" required>' +
            buildCountryOptions(selectedId) +
            '</select></div>' +
            '<div class="form-group col-md-3 mb-1">' +
            '<label>{{ __("Base Price") }} <span class="text-danger">*</span></label>' +
            '<input type="number" name="countries[' + idx + '][price]" class="form-control" value="' + (price || '') + '" min="0" step="0.01" required></div>' +
            '<div class="form-group col-md-3 mb-1">' +
            '<label>{{ __("Offer Price") }}</label>' +
            '<input type="number" name="countries[' + idx + '][offer_price]" class="form-control" value="' + (offerPrice || '') + '" min="0" step="0.01"></div>' +
            '<div class="form-group col-md-2 mb-1">' +
            '<button type="button" class="btn btn-danger btn-sm remove-country-row"><i class="fas fa-trash"></i> {{ __("حذف") }}</button></div>' +
            '</div></div>';
        $('#country-prices-container').append(html);
    }

    $('#add-country-row').on('click', function () {
        addCountryRow(null, '', '');
    });

    $(document).on('click', '.remove-country-row', function () {
        $(this).closest('.country-row').remove();
    });

    // Prevent duplicate country selection
    $(document).on('change', '.country-select', function () {
        var selectedVal = $(this).val();
        var $current = $(this);
        if (!selectedVal) return;

        var isDuplicate = false;
        $('.country-select').each(function () {
            if (this !== $current[0] && $(this).val() === selectedVal) {
                isDuplicate = true;
                return false;
            }
        });
        if (isDuplicate) {
            alert('{{ __("لا يجوز تكرار نفس الدولة") }}');
            $current.val('');
        }
    });

    // Form validation before submit
    $('#plan-form').on('submit', function (e) {
        var rows = $('.country-row');
        if (rows.length === 0) {
            e.preventDefault();
            alert('{{ __("يجب اختيار دولة واحدة على الأقل") }}');
            return false;
        }

        var valid = true;
        rows.each(function () {
            var price = parseFloat($(this).find('input[name$="[price]"]').val());
            var offerPrice = $(this).find('input[name$="[offer_price]"]').val();
            if (offerPrice !== '' && offerPrice !== null && offerPrice !== undefined) {
                offerPrice = parseFloat(offerPrice);
                if (!isNaN(offerPrice) && offerPrice >= price) {
                    alert('{{ __("سعر العرض يجب أن يكون أقل من السعر الأساسي") }}');
                    valid = false;
                    return false;
                }
            }
        });

        if (!valid) {
            e.preventDefault();
            return false;
        }
    });

    // If no old data, add one empty row
    @if (!old('countries'))
        addCountryRow(null, '', '');
    @endif
});
</script>
@endpush
@endsection
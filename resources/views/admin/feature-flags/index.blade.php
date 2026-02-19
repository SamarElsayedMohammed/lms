@extends('layouts.app')

@section('title')
    {{ __('Feature Flags') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
@endsection

@section('main')
<div class="section">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h4>{{ __('Feature Toggles') }}</h4>
                    <div class="card-header-action">
                        <small class="text-muted">{{ __('Enable or disable features from the dashboard.') }}</small>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Key') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th class="text-center">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($flags as $flag)
                                <tr data-flag-id="{{ $flag->id }}">
                                    <td>{{ $flag->name }}</td>
                                    <td><code>{{ $flag->key }}</code></td>
                                    <td>{{ $flag->description ?? '-' }}</td>
                                    <td class="text-center">
                                        <label class="custom-switch">
                                            <input type="checkbox" class="custom-switch-input feature-flag-toggle"
                                                data-id="{{ $flag->id }}"
                                                {{ $flag->is_enabled ? 'checked' : '' }}>
                                            <span class="custom-switch-indicator"></span>
                                        </label>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(document).ready(function() {
    $('.feature-flag-toggle').on('change', function() {
        const $toggle = $(this);
        const id = $toggle.data('id');
        const isEnabled = $toggle.is(':checked');

        $.ajax({
            url: '{{ url("feature-flags") }}/' + id + '/toggle',
            method: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                _method: 'POST'
            },
            success: function(response) {
                if (response.error === false) {
                    $toggle.prop('checked', response.data.is_enabled);
                }
            },
            error: function(xhr) {
                $toggle.prop('checked', !isEnabled);
                alert('{{ __("Failed to update feature flag.") }}');
            }
        });
    });
});
</script>
@endpush

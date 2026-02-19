@extends('layouts.app')

@section('title')
    {{ __('Create Staff') }}
@endsection

@section('page-title')
    <h1 class="mb-0">{{ __('Create Staff') }}</h1>
    <div class="section-header-button ml-auto">
        <a href="{{ route('staffs.index') }}" class="btn btn-primary">← {{ __('Back To Staff Management') }}</a>
    </div> @endsection

@section('main')
    <section class="section">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">
                    {{ __('Create Staff') }}
                </h4>
                <form action="{{ route('staffs.store') }}" method="POST" class="create-form" data-parsley-validate data-success-function="staffCreateSuccess"> @csrf <div class="row g-3">
                        {{-- Role --}}
                        <div class="col-md-6">
                            <div class="form-group mandatory">
                                <label for="role" class="form-label">{{ __('Role') }}</label>
                                <select name="role" id="role" class="form-select" data-parsley-required="true">
                                    <option value="">{{ __('Select Role') }}</option> @foreach ($roles as $role) <option value="{{ $role->name }}">{{ $role->name }}</option> @endforeach </select>
                            </div>
                        </div>

                        {{-- Supervisor Permissions (shown when Supervisor role selected) --}}
                        <div id="supervisor-permissions-section" class="col-12" style="display: none;">
                            <div class="form-group">
                                <label class="form-label">{{ __('Supervisor Permissions') }}</label>
                                <div class="row">
                                    @foreach($supervisorPermissions ?? [] as $perm)
                                    <div class="col-md-4 col-lg-3">
                                        <label class="custom-switch mt-2">
                                            <input type="checkbox" name="supervisor_permissions[]" value="{{ $perm }}" class="custom-switch-input">
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description">{{ __(ucfirst(str_replace('_', ' ', $perm))) }}</span>
                                        </label>
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        {{-- Name --}}
                        <div class="col-md-6">
                            <div class="form-group mandatory">
                                <label for="name" class="form-label">{{ __('Name') }}</label>
                                <input type="text" id="name" name="name" class="form-control"
                                    placeholder="{{ __('Enter Name') }}" data-parsley-required="true">
                            </div>
                        </div>

                        {{-- Email --}}
                        <div class="col-md-6">
                            <div class="form-group mandatory">
                                <label for="email" class="form-label">{{ __('Email') }}</label>
                                <input type="email" id="email" name="email" class="form-control"
                                    placeholder="{{ __('Enter Email') }}" data-parsley-required="true">
                            </div>
                        </div>

                        {{-- Password --}}
                        {{-- <div class="col-md-6">
                            <div class="form-group mandatory">
                                <label for="password" class="form-label">{{ __('Password') }}</label>
                                <input type="password" id="password" name="password" class="form-control"
                                    placeholder="{{ __('Enter Password') }}" data-parsley-required
                                    data-parsley-minlength="8" data-parsley-uppercase="1" data-parsley-lowercase="1"
                                    data-parsley-number="1" data-parsley-special="1">
                            </div>
                        </div> --}}

                        {{-- Is Active --}}
                        <div class="form-group col-sm-12 col-md-6 col-lg-3">
                            <div class="control-label">{{ __('Status') }}</div>
                            <div class="custom-switches-stacked mt-2">
                                <label class="custom-switch">
                                    <input type="checkbox" name="is_active" value="1" class="custom-switch-input">
                                    <span class="custom-switch-indicator"></span>
                                    <span class="custom-switch-description">{{ __('Active') }}</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 text-end">
                        <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection

@section('script')
<script>
    $(function() {
        $('#role').on('change', function() {
            var isSupervisor = $(this).val() === '{{ config('constants.SYSTEM_ROLES.SUPERVISOR') }}';
            $('#supervisor-permissions-section').toggle(isSupervisor);
        }).trigger('change');
    });
    function staffCreateSuccess(response) {
        // Check if redirect_url is in the response
        if (response.redirect_url) {
            window.location.href = response.redirect_url;
        } else {
            // Fallback to default behavior
            if (typeof window.formSuccessFunction === 'function') {
                window.formSuccessFunction(response);
            }
        }
    }
</script>
@endsection

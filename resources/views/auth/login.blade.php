@extends('layouts.auth')

@section('title', 'Admin Login')

@push('style')
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="{{ asset('library/bootstrap-social/bootstrap-social.css') }}">
    <style>
        /* Password field icon alignment */
        .password-input-wrapper {
            position: relative;
        }
        .password-input-wrapper input.form-control {
            padding-right: 44px;
        }
        .password-toggle-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            padding: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
        }
        .password-toggle-btn:focus {
            outline: none;
            box-shadow: none;
        }
        .password-toggle-btn:hover {
            color: #343a40;
        }
        /* Theme primary color for login button */
        .btn-primary {
            background-color: #6777ef !important;
            border-color: #6777ef !important;
        }
        .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
            background-color: #5665d5 !important;
            border-color: #5665d5 !important;
        }
        @if(isset($settings['login_banner_image']) && !empty($settings['login_banner_image']))
        body {
            background-image: url('{{ $settings['login_banner_image'] }}');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            min-height: 100vh;
        }
        .section {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(2px);
        }
        .card {
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        @endif
    </style>
@endpush

@section('main')
    <div class="card card-primary">
        <div class="card-header">
            <h4>{{ __('Admin Login') }}</h4>
        </div>

        <div class="card-body">
            <form method="POST" action="{{ route('login') }}" class="needs-validation" novalidate="">
                @csrf

                {{-- Email --}}
                <div class="form-group">
                    <label for="email">{{ __('Email') }} <span class="text-danger">*</span></label>
                    <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" tabindex="1" required autofocus value="{{ old('email') }}">
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="invalid-feedback" id="email-required-error" style="display: none;">{{ __('Email field is required.') }}</div>
                </div>

                {{-- Password --}}
                <div class="form-group">
                    <div class="d-block">
                        <label for="password" class="control-label">{{ __('Password') }} <span class="text-danger">*</span></label>
                    </div>
                    <div class="password-input-wrapper">
                        <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" tabindex="2" required>
                        <button type="button" class="password-toggle-btn" id="password-toggle">
                            <i class="fas fa-eye" id="password-toggle-icon"></i>
                        </button>
                    </div>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="invalid-feedback" id="password-required-error" style="display: none;">{{ __('Password field is required.') }}</div>
                </div>

                {{-- Remember Me --}}
                <div class="form-group">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" name="remember" class="custom-control-input" tabindex="3" id="remember-me" {{ old('remember') ? 'checked' : '' }}>
                        <label class="custom-control-label" for="remember-me">{{ __('Remember Me') }}</label>
                    </div>
                </div>

                {{-- Login Button --}}
                <div class="form-group">
                    <button type="submit" class="btn btn-primary btn-lg btn-block" tabindex="4">{{ __('Login') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <!-- JS Libraries -->
    <script src="{{ asset('library/jquery-pwstrength/jquery.pwstrength.min.js') }}"></script>
    <script src="{{ asset('js/page/auth-register.js') }}"></script>
    <script>
        $(document).ready(function() {
            // Password toggle functionality
            $('#password-toggle').on('click', function() {
                const passwordInput = $('#password');
                const passwordIcon = $('#password-toggle-icon');
                
                if (passwordInput.attr('type') === 'password') {
                    passwordInput.attr('type', 'text');
                    passwordIcon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    passwordInput.attr('type', 'password');
                    passwordIcon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });

            // Required field validation
            $('form.needs-validation').on('submit', function(e) {
                let isValid = true;
                
                // Check email field
                const emailInput = $('#email');
                const emailError = $('#email-required-error');
                if (!emailInput.val() || emailInput.val().trim() === '') {
                    emailInput.addClass('is-invalid');
                    emailError.show();
                    isValid = false;
                } else {
                    emailInput.removeClass('is-invalid');
                    emailError.hide();
                }

                // Check password field
                const passwordInput = $('#password');
                const passwordError = $('#password-required-error');
                if (!passwordInput.val() || passwordInput.val().trim() === '') {
                    passwordInput.addClass('is-invalid');
                    passwordError.show();
                    isValid = false;
                } else {
                    passwordInput.removeClass('is-invalid');
                    passwordError.hide();
                }

                if (!isValid) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });

            // Clear errors on input
            $('#email').on('input', function() {
                if ($(this).val().trim() !== '') {
                    $(this).removeClass('is-invalid');
                    $('#email-required-error').hide();
                }
            });

            $('#password').on('input', function() {
                if ($(this).val().trim() !== '') {
                    $(this).removeClass('is-invalid');
                    $('#password-required-error').hide();
                }
            });
        });
    </script>
@endpush

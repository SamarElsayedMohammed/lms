@extends('layouts.app')
@section('title')
    {{ __('System Settings') }}
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
                <form action="{{ route('settings.system.update') }}" method="POST" enctype="multipart/form-data" class="create-form" data-success-function="formSuccessFunction" data-pre-submit-function="prepareMaintenanceMode">
                    <div class="card">
                        <div class="card-header">
                            <div class="divider">
                                <div class="divider-text">
                                    {{-- Title --}}
                                    <h4 class="card-title">{{ __('System Settings') }}</h4>
                                </div>
                            </div>
                        </div>
                        <div class="card-body mt-4">
                            <div class="row plr-20">
                                <!-- System Version (Read-only) -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="system_version" class="form-label">{{ __('System Version') }}</label>
                                        <input type="text" name="system_version" class="form-control" id="system_version" value="{{ $settings['system_version'] ?? '1.0.0' }}" readonly disabled style="background-color: #e9ecef; cursor: not-allowed;">
                                        <small class="form-text text-muted">{{ __('Current system version (read-only)') }}</small>
                                    </div>
                                </div>

                                <!-- App Name -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <div class="form-group mandatory">
                                        <label for="app_name" class="form-label">{{ __('App Name') }}</label>
                                        <input type="text" name="app_name" class="form-control" id="app_name" value="{{ $settings['app_name'] ?? '' }}" placeholder="{{ __('App Name') }}" required>
                                    </div>
                                </div>

                                <!-- Website URL -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="website_url" class="form-label">{{ __('Website URL') }}</label>
                                        <input type="url" name="website_url" class="form-control" id="website_url" value="{{ $settings['website_url'] ?? '' }}" placeholder="{{ __('https://example.com') }}">
                                        <small class="form-text text-muted">{{ __('Enter your website URL (e.g., https://example.com)') }}</small>
                                    </div>
                                </div>

                                <!-- Announcement Bar -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="announcement_bar" class="form-label">{{ __('Announcement Bar') }}</label>
                                        <input type="text" name="announcement_bar" class="form-control" id="announcement_bar" value="{{ $settings['announcement_bar'] ?? '' }}" placeholder="{{ __('Enter announcement text') }}">
                                        <small class="form-text text-muted">{{ __('This text will be displayed at the top of your website') }}</small>
                                    </div>
                                </div>

                                    <!-- Favicon -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    @if (isset($settings['favicon']) && $settings['favicon'] != '')
                                    <div class="form-group">
                                            <label for="favicon" class="form-label">{{ __('Favicon') }}</label>
                                            <div class="custom-file">
                                                <input type="file" name="favicon" class="custom-file-input" id="favicon" accept="image/png, image/jpeg, image/jpg, .ico">
                                                <label class="custom-file-label">{{ __('Choose File') }}</label>
                                            </div>
                                            <div class="form-text text-muted"> {{ __('The image must have a maximum size of 1MB') }} </div>
                                            {{-- File Preview --}}
                                            <div class="mt-2">
                                                <img src="{{ $settings['favicon'] }}" alt="Favicon" class="img-thumbnail" style="max-height: 50px;">
                                            </div>
                                        </div> @else <div class="form-group mandatory">
                                            <label for="favicon" class="form-label">{{ __('Favicon') }}</label>
                                            <div class="custom-file">
                                                <input type="file" name="favicon" class="custom-file-input" id="favicon" accept="image/png, image/jpeg, image/jpg, .ico" required>
                                                <label class="custom-file-label">{{ __('Choose File') }}</label>
                                            </div>
                                        </div> @endif </div>


                                <!-- Vertical Logo -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    @if (isset($settings['vertical_logo']) && $settings['vertical_logo'] != '')
                                    <div class="form-group">
                                            <label for="vertical_logo" class="form-label">{{ __('Vertical Logo') }}</label>
                                            <div class="custom-file">
                                                <input type="file" name="vertical_logo" class="custom-file-input" id="vertical_logo">
                                            <label class="custom-file-label">{{ __('Choose File') }}</label>
                                            </div>
                                            <div class="form-text text-muted"> {{ __('The image must have a maximum size of 1MB') }} </div>
                                            {{-- File Preview --}}
                                            <div class="mt-2">
                                                <img src="{{ $settings['vertical_logo'] }}" alt="Vertical Logo" class="img-thumbnail" style="max-height: 100px;">
                                            </div>
                                        </div> @else <div class="form-group mandatory">
                                            <label for="vertical_logo" class="form-label">{{ __('Vertical Logo') }}</label>
                                            <div class="custom-file">
                                                <input type="file" name="vertical_logo" class="custom-file-input" id="vertical_logo" accept="image/png, image/jpeg, image/jpg" required>
                                                <label class="custom-file-label">{{ __('Choose File') }}</label>
                                            </div>
                                        </div> @endif </div>

                                <!-- Horizontal Logo -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    @if (isset($settings['horizontal_logo']) && $settings['horizontal_logo'] != '')
                                    <div class="form-group">
                                            <label for="horizontal_logo" class="form-label">{{ __('Horizontal Logo') }} ({{ __('Web Logo') }})</label>
                                            <div class="custom-file">
                                                <input type="file" name="horizontal_logo" class="custom-file-input" id="horizontal_logo">
                                                <label class="custom-file-label" for="horizontal_logo">{{ __('Choose File') }}</label>
                                            </div>
                                            <div class="form-text text-muted"> {{ __('The image must have a maximum size of 1MB') }} </div>
                                            {{-- File Preview --}}
                                            <div class="mt-2">
                                                <img src="{{ $settings['horizontal_logo'] }}" alt="Horizontal Logo" class="img-thumbnail" style="max-height: 100px;">
                                            </div>
                                        </div> @else <div class="form-group mandatory">
                                            <label for="horizontal_logo" class="form-label">{{ __('Horizontal Logo') }} ({{ __('Web Logo') }})</label>
                                            <div class="custom-file">
                                                <input type="file" name="horizontal_logo" class="custom-file-input" id="horizontal_logo" accept="image/png, image/jpeg, image/jpg" required>
                                                <label class="custom-file-label">{{ __('Choose File') }}</label>
                                            </div>
                                        </div> @endif </div>

                                <!-- Placeholder Image -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    @if (isset($settings['placeholder_image']) && $settings['placeholder_image'] != '')
                                    <div class="form-group">
                                            <label for="placeholder_image" class="form-label">{{ __('Placeholder Image') }}</label>
                                            <div class="custom-file">
                                                <input type="file" name="placeholder_image" class="custom-file-input" id="placeholder_image" accept="image/png, image/jpeg, image/jpg, image/webp">
                                                <label class="custom-file-label" for="placeholder_image">{{ __('Choose File') }}</label>
                                            </div>
                                            <div class="form-text text-muted"> {{ __('The image must have a maximum size of 2MB') }} </div>
                                            {{-- File Preview --}}
                                            <div class="mt-2">
                                                <img src="{{ $settings['placeholder_image'] }}" alt="Placeholder Image" class="img-thumbnail" style="max-height: 100px;">
                                            </div>
                                        </div> @else <div class="form-group">
                                            <label for="placeholder_image" class="form-label">{{ __('Placeholder Image') }}</label>
                                            <div class="custom-file">
                                                <input type="file" name="placeholder_image" class="custom-file-input" id="placeholder_image" accept="image/png, image/jpeg, image/jpg, image/webp">
                                                <label class="custom-file-label">{{ __('Choose File') }}</label>
                                            </div>
                                            <small class="form-text text-muted">{{ __('Default image to display when no image is available') }}</small>
                                        </div> @endif </div>

                                <!-- Login Banner Image -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    @if (isset($settings['login_banner_image']) && $settings['login_banner_image'] != '')
                                    <div class="form-group">
                                            <label for="login_banner_image" class="form-label">{{ __('Login Banner Image') }}</label>
                                            <div class="custom-file">
                                                <input type="file" name="login_banner_image" class="custom-file-input" id="login_banner_image" accept="image/png, image/jpeg, image/jpg, image/webp">
                                                <label class="custom-file-label" for="login_banner_image">{{ __('Choose File') }}</label>
                                            </div>
                                            <div class="form-text text-muted"> {{ __('The image must have a maximum size of 2MB') }} </div>
                                            {{-- File Preview --}}
                                            <div class="mt-2">
                                                <img src="{{ $settings['login_banner_image'] }}" alt="Login Banner Image" class="img-thumbnail" style="max-height: 100px;">
                                            </div>
                                        </div> @else <div class="form-group">
                                            <label for="login_banner_image" class="form-label">{{ __('Login Banner Image') }}</label>
                                            <div class="custom-file">
                                                <input type="file" name="login_banner_image" class="custom-file-input" id="login_banner_image" accept="image/png, image/jpeg, image/jpg, image/webp">
                                                <label class="custom-file-label">{{ __('Choose File') }}</label>
                                            </div>
                                            <small class="form-text text-muted">{{ __('Banner image displayed on the login page') }}</small>
                                        </div> @endif </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <div class="divider">
                                <div class="divider-text">
                                    {{-- Title --}}
                                    <h4 class="card-title">{{ __('Contact Information') }}</h4>
                                </div>
                            </div>
                        </div>
                        <div class="card-body mt-4">
                            <div class="row plr-20">
                                <!-- Contact Address -->
                                <div class="col-lg-12 col-md-12 col-sm-12">
                                    <div class="form-group">
                                        <label for="contact_address" class="form-label">{{ __('Contact Address') }}</label>
                                        <textarea name="contact_address" class="form-control" id="contact_address" rows="3" placeholder="{{ __('Enter contact address') }}">{{ $settings['contact_address'] ?? '' }}</textarea>
                                        <small class="form-text text-muted">{{ __('Your business or organization address') }}</small>
                                    </div>
                                </div>

                                <!-- Contact Email -->
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="contact_email" class="form-label">{{ __('Contact Email') }}</label>
                                        <input type="email" name="contact_email" class="form-control" id="contact_email" value="{{ $settings['contact_email'] ?? '' }}" placeholder="{{ __('contact@example.com') }}">
                                        <small class="form-text text-muted">{{ __('Email address for contact inquiries') }}</small>
                                    </div>
                                </div>

                                <!-- Contact Phone -->
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="contact_phone" class="form-label">{{ __('Contact Phone') }}</label>
                                        <input type="text" name="contact_phone" class="form-control" id="contact_phone" value="{{ $settings['contact_phone'] ?? '' }}" placeholder="{{ __('+1 234 567 8900') }}">
                                        <small class="form-text text-muted">{{ __('Phone number for contact inquiries') }}</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <div class="divider">
                                <div class="divider-text">
                                    {{-- Title --}}
                                    <h4 class="card-title">{{ __('Other Settings') }}</h4>
                                </div>
                            </div>
                        </div>
                        <div class="card-body mt-4">
                            <div class="row plr-20">

                                <!-- Countries -->
                                <div class="col-sm-12 col-md-6 col-lg-4 mt-2 form-group mandatory">
                                    <label class="col-sm-12 form-label" for="currency-code">{{ __('Currency Name') }}</label>
                                    <select id="currency-code" class="form-select form-control-sm select2" name="currency_code" required data-parsley-required-message="{{ __('Currency Name is required') }}">
                                        <option value="">{{ __('Select Currency') }}</option> @if(!empty($listOfCurrencies))
                                            @foreach ($listOfCurrencies as $data) <option value="{{ $data['currency_code'] }}" {{ isset($settings['currency_code']) && $settings['currency_code'] == $data['currency_code'] ? 'selected' : '' }}>{{ $data['currency_name'] }}</option> @endforeach
                                        @endif </select>
                                    <input type="hidden" id="url-for-currency-symbol" value="{{ route('get-currency-symbol') }}">
                                </div>

                                <!-- Currency Symbol -->
                                <div class="col-sm-12 col-md-6 col-lg-4 mt-2 form-group mandatory">
                                    <label class="col-sm-12 form-label " for="curreny-symbol">{{ __('Currency Symbol') }}</label>
                                    <input name="currency_symbol" type="text" id="currency-symbol" class="form-control" placeholder="{{ __('Currency Symbol') }}" required maxlength="5" value="{{ isset($settings['currency_symbol']) && $settings['currency_symbol'] != '' ? $settings['currency_symbol'] : '' }}" data-parsley-required-message="{{ __('Currency Symbol is required') }}">
                                </div>

                                <!-- System Color -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <label class="col-sm-12 mt-2" class="form-label" for="system_color">{{ __('System Color') }}</label>
                                    <input name="system_color" class="form-control jscolor" data-jscolor="{hash:true, alphaChannel:true}"
                                    value="{{ isset($settings['system_color']) && $settings['system_color'] != '' ? $settings['system_color'] : '#E48D18FF' }}">
                                </div>

                                <!-- System Light Color -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <label class="col-sm-12 mt-2" class="form-label" for="system_light_color">{{ __('System Light Color') }}</label>
                                    <input name="system_light_color" class="form-control jscolor" data-jscolor="{hash:true, alphaChannel:true}"
                                    value="{{ isset($settings['system_light_color']) && $settings['system_light_color'] != '' ? $settings['system_light_color'] : '#E48D18FF' }}">
                                </div>

                                <!-- Hover Color -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <label class="col-sm-12 mt-2" class="form-label" for="hover_color">{{ __('Hover Color') }}</label>
                                    <input name="hover_color" class="form-control jscolor" data-jscolor="{hash:true, alphaChannel:true}"
                                    value="{{ isset($settings['hover_color']) && $settings['hover_color'] != '' ? $settings['hover_color'] : '#4A4B9AFF' }}">
                                </div>

                                <!-- Footer Description -->
                                <div class="col-lg-12 col-md-12 col-sm-12">
                                    <div class="form-group">
                                        <label for="footer_description" class="form-label">{{ __('Footer Description') }}</label>
                                        <textarea name="footer_description" class="form-control" id="footer_description" rows="3" placeholder="{{ __('Enter footer description') }}">{{ $settings['footer_description'] ?? '' }}</textarea>
                                    </div>
                                </div>

                                <!-- Website Copyright -->
                                <div class="col-lg-12 col-md-12 col-sm-12">
                                    <div class="form-group">
                                        <label for="website_copyright" class="form-label">{{ __('Website Copyright') }}</label>
                                        <textarea name="website_copyright" class="form-control tinymce-editor" id="website_copyright" rows="3">{{ $settings['website_copyright'] ?? '' }}</textarea>
                                        <small class="form-text text-muted">
                                            {{ __('copyright_helper_text') }}<br>
                                            {{ __('copyright_example') }}
                                        </small>
                                    </div>
                                </div>

                                <!-- Schema -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="schema" class="form-label">{{ __('Schema') }}</label>
                                        <input type="text" name="schema" class="form-control" id="schema" value="{{ $settings['schema'] ?? '' }}" placeholder="{{ __('Enter schema (lowercase letters only)') }}" pattern="[a-z]*" oninput="this.value = this.value.toLowerCase().replace(/[^a-z]/g, '')">
                                        <small class="form-text text-muted">{{ __('Only lowercase letters [a-z] are allowed') }}</small>
                                    </div>
                                </div>

                                <!-- Maximum Video Upload Size -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="max_video_upload_size" class="form-label">{{ __('Maximum Upload Size for Videos (MB)') }}</label>
                                        <input type="number" name="max_video_upload_size" class="form-control" id="max_video_upload_size" value="{{ $settings['max_video_upload_size'] ?? '10' }}" placeholder="{{ __('Enter maximum size in MB') }}" min="1" step="1">
                                        <small class="form-text text-muted">{{ __('Maximum file size allowed for course video uploads (in MB)') }}</small>
                                    </div>
                                </div>

                                <!-- Timezone -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="timezone" class="form-label">{{ __('Timezone') }}</label>
                                        <select name="timezone" id="timezone" class="form-control">
                                            <option value="UTC" {{ (isset($settings['timezone']) && $settings['timezone'] == 'UTC') ? 'selected' : '' }}>UTC</option>
                                            <option value="Asia/Kolkata" {{ (isset($settings['timezone']) && $settings['timezone'] == 'Asia/Kolkata') ? 'selected' : '' }}>Asia/Kolkata (IST)</option>
                                            <option value="America/New_York" {{ (isset($settings['timezone']) && $settings['timezone'] == 'America/New_York') ? 'selected' : '' }}>America/New_York (EST)</option>
                                            <option value="America/Chicago" {{ (isset($settings['timezone']) && $settings['timezone'] == 'America/Chicago') ? 'selected' : '' }}>America/Chicago (CST)</option>
                                            <option value="America/Denver" {{ (isset($settings['timezone']) && $settings['timezone'] == 'America/Denver') ? 'selected' : '' }}>America/Denver (MST)</option>
                                            <option value="America/Los_Angeles" {{ (isset($settings['timezone']) && $settings['timezone'] == 'America/Los_Angeles') ? 'selected' : '' }}>America/Los_Angeles (PST)</option>
                                            <option value="Europe/London" {{ (isset($settings['timezone']) && $settings['timezone'] == 'Europe/London') ? 'selected' : '' }}>Europe/London (GMT)</option>
                                            <option value="Europe/Paris" {{ (isset($settings['timezone']) && $settings['timezone'] == 'Europe/Paris') ? 'selected' : '' }}>Europe/Paris (CET)</option>
                                            <option value="Asia/Dubai" {{ (isset($settings['timezone']) && $settings['timezone'] == 'Asia/Dubai') ? 'selected' : '' }}>Asia/Dubai (GST)</option>
                                            <option value="Asia/Singapore" {{ (isset($settings['timezone']) && $settings['timezone'] == 'Asia/Singapore') ? 'selected' : '' }}>Asia/Singapore (SGT)</option>
                                            <option value="Asia/Tokyo" {{ (isset($settings['timezone']) && $settings['timezone'] == 'Asia/Tokyo') ? 'selected' : '' }}>Asia/Tokyo (JST)</option>
                                            <option value="Australia/Sydney" {{ (isset($settings['timezone']) && $settings['timezone'] == 'Australia/Sydney') ? 'selected' : '' }}>Australia/Sydney (AEDT)</option>
                                        </select>
                                        <small class="form-text text-muted">{{ __('Select the timezone for your application. This affects how dates and times are displayed.') }}</small>
                                    </div>
                                </div>

                                <!-- Maintenance Mode -->
                                <div class="col-lg-4 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <div class="control-label">{{ __('Maintenance Mode') }}</div>
                                        <div class="custom-switches-stacked mt-2">
                                            <label class="custom-switch">
                                                <input type="checkbox" name="maintaince_mode" value="1" class="custom-switch-input" id="maintaince-mode">
                                                <span class="custom-switch-indicator"></span>
                                                <span class="custom-switch-description">{{ __('Enable') }}</span>
                                            </label>
                                        </div>
                                        <small class="form-text text-muted">{{ __('Enable maintenance mode to temporarily disable the system') }}</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <div class="divider">
                                <div class="divider-text">
                                    <h4 class="card-title">{{ __('Commission Settings') }}</h4>
                                </div>
                            </div>
                        </div>
                        <div class="card-body plr-50">
                            <!-- Explanation -->
                            <div class="alert" style="background-color: #f8f9fa; border: 1px solid #dee2e6; border-left: 4px solid #0d6efd;">
                                <h6 class="mb-3"><strong style="color: #0d6efd;">{{ __('How Commission Works:') }}</strong></h6>
                                <p class="mb-2" style="line-height: 1.6; color: #212529;">{{ __('Set your platform fee percentage. Instructors automatically receive the remaining percentage.') }}</p>
                                <p class="mb-0" style="line-height: 1.6; color: #212529;"><strong>{{ __('Example:') }}</strong> {{ __('If platform fee is 5% on a $100 course, platform gets $5 and instructor gets $95.') }}</p>
                            </div>

                            <div class="row">
                                <!-- Individual Instructors -->
                                <div class="col-md-6 mb-4">
                                    <div class="p-4 rounded" style="background: linear-gradient(135deg, #e3f2fd 0%, #f0f7ff 100%);">
                                        <h5 class="mb-4 d-flex align-items-center" style="color: #1565c0; font-weight: 600;">
                                            <i class="fas fa-user me-2" style="color: #1976d2;"></i>
                                            <span>{{ __('Individual Instructors') }}</span>
                                        </h5>

                                        <!-- Platform Fee (Editable) -->
                                        <div class="form-group mandatory mb-4">
                                            <label class="form-label fw-bold d-block mb-2" for="individual_admin_commission" style="color: #212529; font-size: 0.95rem;">
                                                {{ __('Platform Fee (%)') }}
                                            </label>
                                            <input
                                                name="individual_admin_commission"
                                                type="text"
                                                inputmode="decimal"
                                                id="individual_admin_commission"
                                                class="form-control form-control-lg"
                                                placeholder="5"
                                                required
                                                pattern="^([0-9]|[1-9][0-9]|100)(\.[0-9]{1,2})?$"
                                                title="Please enter a number between 0 and 100 (up to 2 decimal places)"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                value="{{ isset($settings['individual_admin_commission']) ? $settings['individual_admin_commission'] : '5' }}"
                                                oninput="validateCommissionInput(this)"
                                                style="font-size: 1.1rem; background-color: rgba(255, 255, 255, 0.7); border: 1px solid #bbdefb;"
                                            >
                                            <small class="d-block mt-2" style="color: #6c757d;">{{ __('What the platform keeps from each sale') }}</small>
                                        </div>

                                        <!-- Instructor Earns (Auto-calculated) -->
                                        <div class="form-group">
                                            <label class="form-label d-block mb-2" for="individual_instructor_commission" style="color: #212529; font-size: 0.95rem;">
                                                {{ __('Instructor Earns (%)') }}
                                                <span class="badge bg-success ms-2" style="font-size: 0.7rem; vertical-align: middle;">{{ __('Auto-calculated') }}</span>
                                            </label>
                                            <input
                                                name="individual_instructor_commission"
                                                id="individual_instructor_commission"
                                                class="form-control"
                                                readonly
                                                tabindex="-1"
                                                value="{{ isset($settings['individual_admin_commission']) ? (100 - $settings['individual_admin_commission']) : '95' }}"
                                                style="background-color: #d1e7f5; cursor: not-allowed; border: 1px solid #bbdefb;"
                                            >
                                        </div>
                                    </div>
                                </div>

                                <!-- Team Instructors -->
                                <div class="col-md-6 mb-4">
                                    <div class="p-4 rounded" style="background: linear-gradient(135deg, #f3e5f5 0%, #faf4ff 100%);">
                                        <h5 class="mb-4 d-flex align-items-center" style="color: #6a1b9a; font-weight: 600;">
                                            <i class="fas fa-users me-2" style="color: #7b1fa2;"></i>
                                            <span>{{ __('Team Instructors') }}</span>
                                        </h5>

                                        <!-- Platform Fee (Editable) -->
                                        <div class="form-group mandatory mb-4">
                                            <label class="form-label fw-bold d-block mb-2" for="team_admin_commission" style="color: #212529; font-size: 0.95rem;">
                                                {{ __('Platform Fee (%)') }}
                                            </label>
                                            <input
                                                name="team_admin_commission"
                                                type="text"
                                                inputmode="decimal"
                                                id="team_admin_commission"
                                                class="form-control form-control-lg"
                                                placeholder="10"
                                                required
                                                pattern="^([0-9]|[1-9][0-9]|100)(\.[0-9]{1,2})?$"
                                                title="Please enter a number between 0 and 100 (up to 2 decimal places)"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                value="{{ isset($settings['team_admin_commission']) ? $settings['team_admin_commission'] : '10' }}"
                                                oninput="validateCommissionInput(this)"
                                                style="font-size: 1.1rem; background-color: rgba(255, 255, 255, 0.7); border: 1px solid #e1bee7;"
                                            >
                                            <small class="d-block mt-2" style="color: #6c757d;">{{ __('What the platform keeps from each sale') }}</small>
                                        </div>

                                        <!-- Instructor Earns (Auto-calculated) -->
                                        <div class="form-group">
                                            <label class="form-label d-block mb-2" for="team_instructor_commission" style="color: #212529; font-size: 0.95rem;">
                                                {{ __('Instructor Earns (%)') }}
                                                <span class="badge bg-success ms-2" style="font-size: 0.7rem; vertical-align: middle;">{{ __('Auto-calculated') }}</span>
                                            </label>
                                            <input
                                                name="team_instructor_commission"
                                                id="team_instructor_commission"
                                                class="form-control"
                                                readonly
                                                tabindex="-1"
                                                value="{{ isset($settings['team_admin_commission']) ? (100 - $settings['team_admin_commission']) : '90' }}"
                                                style="background-color: #e8d5f0; cursor: not-allowed; border: 1px solid #e1bee7;"
                                            >
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Additional Info -->
                            <div class="alert plr-20" style="background-color: #f8f9fa; border: 1px solid #dee2e6; border-left: 4px solid #6c757d;">
                                <p class="mb-2"><strong style="color: #212529;">{{ __('Note:') }}</strong></p>
                                <ul class="mb-0 ps-3" style="color: #495057; line-height: 1.8;">
                                    <li>{{ __('Platform fee is applied to the discounted course price (after coupons)') }}</li>
                                    <li>{{ __('Coupon discounts are allocated proportionally based on each course\'s original price') }}</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <div class="divider">
                                <div class="divider-text">
                                    {{-- Title --}}
                                    <h4 class="card-title">{{ __('Instructor Mode Settings') }}</h4>
                                </div>
                            </div>
                        </div>
                        <div class="card-body mt-4">
                            <div class="row plr-20">
                                <!-- Instructor Mode -->
                                <div class="col-sm-12 col-md-6 col-lg-4 mt-2 form-group mandatory">
                                    <label class="form-label" for="instructor_mode">{{ __('Instructor Mode') }}</label>
                                    <select name="instructor_mode" id="instructor_mode" class="form-control" required>
                                        <option value="single" {{ (isset($settings['instructor_mode']) && $settings['instructor_mode'] == 'single') ? 'selected' : '' }}>
                                            {{ __('Single Instructor (Admin as Instructor)') }}
                                        </option>
                                        <option value="multi" {{ (isset($settings['instructor_mode']) && $settings['instructor_mode'] == 'multi') ? 'selected' : '' }}>
                                            {{ __('Multi Instructor System') }}
                                        </option>
                                    </select>
                                    <small class="form-text text-muted">
                                        {{ __('Single: Admin acts as the only instructor. Multi: Separate instructor accounts allowed.') }}
                                    </small>
                                </div>
                            </div>
                            <div class="row mt-3 plr-20">
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <strong>{{ __('Note:') }}</strong>
                                        <ul class="mb-0 mt-2">
                                            <li>{{ __('Single Instructor Mode: Admin will have instructor permissions and capabilities. Instructor lists and filters will be hidden.') }}</li>
                                            <li>{{ __('Multi Instructor Mode: Separate instructor accounts can be created and managed. Full instructor management features available.') }}</li>
                                            <li>{{ __('Changing this setting will affect how the system handles instructor-related functionality.') }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <div class="divider">
                                <div class="divider-text">
                                    {{-- Title --}}
                                    <h4 class="card-title">{{ __('Social Media') }}</h4>
                                </div>
                            </div>
                        </div>
                        <div class="card-body mt-4">
                            <div class="row plr-20">

                                <!-- Social Media -->
                                <div class="form-group mandatory col-12">
                                    <div class="social-media-section">
                                        <div data-repeater-list="social_media_data">
                                            <div class="row learning-section d-flex align-items-center mb-2" data-repeater-item>
                                                <input type="hidden" name="id" class="id">
                                                {{-- Name --}}
                                                <div class="form-group mandatory col-md-12 col-lg-4">
                                                    <label class="form-label">{{ __('Name') }} - <span class="sr-number"> {{ __('0') }} </span></label>
                                                    <input type="text" name="name" class="form-control" placeholder="{{ __('Enter a name') }}" required data-parsley-required="true">
                                                </div>
                                                {{-- Icon --}}
                                                <div class="form-group mandatory col-md-12 col-lg-3">
                                                    <label class="form-label">{{ __('Icon') }} - <span class="sr-number"> {{ __('0') }} </span></label>
                                                    <input type="file" name="icon" class="form-control social-media-icon-input" placeholder="{{ __('Enter a icon') }}" required data-parsley-required="true" accept="image/png, image/jpeg, image/jpg">
                                                    {{-- File Preview --}}
                                                    <div class="mt-2 social-media-icon-preview" style="display: none;">
                                                        <img src="" alt="Social Media Icon" class="img-thumbnail social-media-icon" style="max-height: 50px;">
                                                    </div>
                                                </div>
                                                {{-- Link/URL --}}
                                                <div class="form-group mandatory col-md-12 col-lg-4">
                                                    <label class="form-label">{{ __('Link') }} - <span class="sr-number"> {{ __('0') }} </span></label>
                                                    <input type="url" name="url" class="form-control" placeholder="{{ __('https://example.com') }}" required data-parsley-required="true" data-parsley-type="url">
                                                    <small class="form-text text-muted">{{ __('Social media profile URL') }}</small>
                                                </div>
                                                {{-- Remove Social Media --}}
                                                <div class="form-group col-md-12 col-lg-1 mt-4">
                                                    <button data-repeater-delete type="button" class="btn btn-danger remove-social-media" title="{{ __('remove') }}">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        {{-- Add New Social Media --}}
                                        <button type="button" class="btn btn-success mt-1 add-new-social-media" data-repeater-create title="{{ __('Add New Social Media') }}">
                                            <i class="fa fa-plus"></i> {{ __('Add New Social Media') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <div class="divider">
                                <div class="divider-text">
                                    {{-- Title --}}
                                    <h4 class="card-title">{{ __('Refund Settings') }}</h4>
                                </div>
                            </div>
                        </div>
                        <div class="card-body mt-4">
                            <div class="row plr-20">
                                <!-- Refund Status -->
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group mandatory">
                                        <label for="refund_enabled" class="form-label">{{ __('Enable Refunds') }}</label>
                                        <select name="refund_enabled" id="refund_enabled" class="form-control" required>
                                            <option value="1" {{ isset($settings['refund_enabled']) && $settings['refund_enabled'] == 1 ? 'selected' : '' }}>{{ __('Enabled') }}</option>
                                            <option value="0" {{ isset($settings['refund_enabled']) && $settings['refund_enabled'] == 0 ? 'selected' : '' }}>{{ __('Disabled') }}</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Refund Days -->
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group mandatory">
                                        <label for="refund_period_days" class="form-label">{{ __('Refund Period (Days)') }}</label>
                                        <input type="number" name="refund_period_days" class="form-control" id="refund_period_days" value="{{ $settings['refund_period_days'] ?? 7 }}" min="0" placeholder="{{ __('Number of days allowed for refund') }}" required>
                                        <small class="form-text text-muted">{{ __('Number of days after purchase when refunds are allowed') }}</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-4">
                        <div class="col-12 text-right flex-end">
                            <button class="btn btn-primary" id="save-btn">{{ __('Update') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div> @endsection

@push('scripts')
<script>
        // Pre-submit function to handle maintenance mode checkbox
        window.prepareMaintenanceMode = function() {
            // Remove any existing hidden input first
            $('#maintaince-mode-hidden').remove();

            // If checkbox is unchecked, add hidden input with value 0
            if (!$('#maintaince-mode').is(':checked')) {
                // Create hidden input and add it to form
                var hiddenInput = $('<input>').attr({
                    type: 'hidden',
                    name: 'maintaince_mode',
                    value: '0',
                    id: 'maintaince-mode-hidden'
                });
                // Insert it right after the checkbox
                $('#maintaince-mode').after(hiddenInput);
            }
            return true; // Allow form submission to proceed
        };

        $(document).ready(function() {
            // Fix Select2 with Parsley validation
            // Initialize select2 first (if not already initialized)
            if (!$('#currency-code').hasClass('select2-hidden-accessible')) {
                $('#currency-code').select2();
            }

            // Make sure parsley doesn't exclude the select element
            $('#currency-code').attr('data-parsley-excluded', 'false');

            // Trigger validation on select2 events
            $('#currency-code').on('select2:select select2:unselect', function(e) {
                // Clear any previous errors
                $(this).parsley().reset();
                // Validate the field
                $(this).parsley().validate();
            });

            // Ensure select2 change also triggers validation
            $('#currency-code').on('change', function() {
                var value = $(this).val();
                if (value) {
                    // If value exists, clear error
                    $(this).parsley().reset();
                } else {
                    // If no value, validate to show error
                    $(this).parsley().validate();
                }
            });

            // Override Parsley's default excluded option to include hidden select elements
            window.Parsley.options.excluded = 'input[type=button], input[type=submit], input[type=reset], input[type=hidden]';

            // Maintenance Mode
            let maintainceMode = {{ $settings['maintaince_mode'] ?? 0 }};
            if(maintainceMode == 1 || maintainceMode == '1') {
                $('#maintaince-mode').prop('checked', true).trigger('change');
            } else {
                $('#maintaince-mode').prop('checked', false);
            }

            // Social Media Repeater
            @if(collect($socialMedias)->isNotEmpty())
                socialMediaRepeater.setList([
                    @foreach($socialMedias as $socialMedia)
                        {
                            "id": {{ $socialMedia->id }},
                            "name": "{{ $socialMedia->name }}",
                            "url": "{{ $socialMedia->url ?? '' }}"
                        },
                    @endforeach
                ]);

                @foreach($socialMedias as $key =>$socialMedia)
                    @if($socialMedia->icon)
                        $('#social-media-icon-input-{{ $key + 1 }}').removeAttr('required').removeAttr('data-parsley-required');
                        $('#social-media-icon-preview-{{ $key + 1 }}').find('.social-media-icon').attr('src', '{{ $socialMedia->icon }}');
                        $('#social-media-icon-preview-{{ $key + 1 }}').show();
                    @else
                        $('#social-media-icon-preview-{{ $key + 1 }}').attr('required', true).attr('data-parsley-required', true);
                    @endif
                @endforeach
            @endif
        });
        function formSuccessFunction() {
            location.reload();
        }

        function validateCommissionInput(input) {
            // Remove any non-numeric characters except decimal point
            let value = input.value.replace(/[^0-9.]/g, '');

            // Ensure only one decimal point
            const parts = value.split('.');
            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }

            // Limit to 2 decimal places
            if (parts[1] && parts[1].length > 2) {
                value = parts[0] + '.' + parts[1].substring(0, 2);
            }

            // Convert to number and validate range
            let numValue = parseFloat(value);
            if (!isNaN(numValue)) {
                if (numValue < 0) {
                    value = '0';
                } else if (numValue > 100) {
                    value = '100';
                }
            }

            // Update the input value
            input.value = value;

            // Update commissions
            updateCommissions();
        }

        function updateCommissions() {
            // Update Individual Instructor Commission
            var individualAdminCommission = parseFloat(document.getElementById('individual_admin_commission').value) || 0;
            var individualInstructorCommission = 100 - individualAdminCommission;
            if(individualInstructorCommission < 0) individualInstructorCommission = 0;
            document.getElementById('individual_instructor_commission').value = individualInstructorCommission.toFixed(2);

            // Update Team Instructor Commission
            var teamAdminCommission = parseFloat(document.getElementById('team_admin_commission').value) || 0;
            var teamInstructorCommission = 100 - teamAdminCommission;
            if(teamInstructorCommission < 0) teamInstructorCommission = 0;
            document.getElementById('team_instructor_commission').value = teamInstructorCommission.toFixed(2);
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateCommissions();
        });

        // Prevent admin commissions from exceeding 100
        document.addEventListener('DOMContentLoaded', function() {
            var individualAdminInput = document.getElementById('individual_admin_commission');
            var teamAdminInput = document.getElementById('team_admin_commission');

            individualAdminInput.addEventListener('input', function() {
                if (parseFloat(this.value) > 100) {
                    this.value = 100;
                }
                updateCommissions();
            });

            teamAdminInput.addEventListener('input', function() {
                if (parseFloat(this.value) > 100) {
                    this.value = 100;
                }
                updateCommissions();
            });
        });
    </script>
@endpush

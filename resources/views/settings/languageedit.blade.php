@extends('layouts.app')

@section('title')
    {{ __('Edit Languages') }}
@endsection

@section('page-title')
   <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto"></div> @endsection
@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('Edit') . ' ' . __('Language') }}
                        </h4>

                        {{-- Form start --}}
                        <form action="{{ route('language.update', $language_data->id) }}" method="POST" data-parsley-validate enctype="multipart/form-data">
                            @method('PUT')
                        @csrf
                         <div class="row">
        <div class="col-sm-12 col-md-6 form-group mandatory">
            <label for="name" class="form-label">{{ __('Language Name') }}</label>
            <input type="text" name="name" id="name" class="form-control" placeholder="{{ __('Language Name') }}" data-parsley-required="true" value="{{ $language_data->name }}">
        </div>

        <div class="col-sm-12 col-md-6 form-group mandatory">
            <label for="name_in_english" class="form-label">{{ __('Language Name') }} ({{ __('in English') }})</label>
            <input type="text" name="name_in_english" id="name_in_english" class="form-control" placeholder="{{ __('Language Name') }} ({{ __('in English') }})" data-parsley-required="true" value="{{ $language_data->name_in_english }}">
        </div>

        <div class="col-sm-12 col-md-6 form-group mandatory">
            <label for="code" class="form-label">{{ __('Language Code') }}</label>
            <input type="text" name="code" id="code" class="form-control" placeholder="{{ __('Language Code') }}" data-parsley-required="true" value="{{ $language_data->code }}">
        </div>

        <div class="col-sm-12 col-md-6 form-group mandatory">
            <label for="country_code" class="form-label">
                {{ __('Country Code') }} ({{ __('ISO 3166-1 alpha-2, e.g., IN, US, GB') }})
            </label>
            <input type="text" name="country_code" id="country_code" class="form-control" placeholder="{{ __('Country Code') }}" data-parsley-required="true" value="{{ $language_data->country_code }}">
        </div>

        <div class="col-sm-12 col-md-4 form-group">
            <label class="form-label">{{ __('App Translation JSON') }}</label>
            <div class="d-flex align-items-center gap-2">
                <input class="form-control" type="file" name="app_file" accept=".json,application/json">
                <a href="{{ route('language.download.sample', 'app') }}" class="btn btn-sm btn-outline-primary" title="{{ __('Download Sample File') }}" download>
                    <i class="fa fa-download"></i>
                </a>
            </div>
            <small class="form-text text-muted">{{ __('Only JSON files are allowed') }} | <a href="{{ route('language.download.sample', 'app') }}" class="text-primary">{{ __('Download Sample') }}</a></small>
            @if (!empty($language_data->app_file)) <div class="mt-2">
                    <a href="{{ route('language.view.json', ['locale' => $language_data->app_file]) }}" target="_blank">
                        {{ $language_data->app_file }}
                    </a>
                </div> @endif </div>

        <div class="col-sm-12 col-md-4 form-group">
            <label class="form-label">{{ __('Panel Translation JSON') }}</label>
            <div class="d-flex align-items-center gap-2">
                <input class="form-control" type="file" name="panel_file" accept=".json,application/json">
                <a href="{{ route('language.download.sample', 'panel') }}" class="btn btn-sm btn-outline-primary" title="{{ __('Download Sample File') }}" download>
                    <i class="fa fa-download"></i>
                </a>
            </div>
            <small class="form-text text-muted">{{ __('Only JSON files are allowed') }} | <a href="{{ route('language.download.sample', 'panel') }}" class="text-primary">{{ __('Download Sample') }}</a></small>
        </div>

        <div class="col-sm-12 col-md-4 form-group">
            <label class="form-label">{{ __('Web Translation JSON') }}</label>
            <div class="d-flex align-items-center gap-2">
                <input class="form-control" type="file" name="web_file" accept=".json,application/json">
                <a href="{{ route('language.download.sample', 'web') }}" class="btn btn-sm btn-outline-primary" title="{{ __('Download Sample File') }}" download>
                    <i class="fa fa-download"></i>
                </a>
            </div>
            <small class="form-text text-muted">{{ __('Only JSON files are allowed') }} | <a href="{{ route('language.download.sample', 'web') }}" class="text-primary">{{ __('Download Sample') }}</a></small>
        </div>

        <div class="col-md-6 form-group mandatory">
            <label for="image" class="form-label mandatory">{{ __('Image') }}</label>
            <div class="cs_field_img">
                <input type="file" name="image" class="image" style="display: none"
                    accept=".jpg, .jpeg, .png, .svg">
                <img src="{{ $language_data->image }}" alt="" class="img preview-image">
                <div class="img_input">{{ __('Browse File') }}</div>
            </div>
            <div class="input_hint">{{ __('Icon (use 256 x 256 size for better view)') }}</div>
            <div class="img_error" style="color:#DC3545;"></div>
        </div>

        <div class="col-sm-1 col-md-6">
            <div class="custom-switches-stacked mt-2">
                <div class="control-label">{{ __('RTL') }}</div>
                <label class="custom-switch">
                    <input type="hidden" name="rtl" value="0">
                    <input type="checkbox" name="rtl" value="1" id="{{ $language_data->id }}" {{ $language_data->rtl == 1 ? 'checked' : '' }} class="custom-switch-input">
                    <span class="custom-switch-indicator"></span>   
                </label>
            </div>
        </div>

        <div class="col-sm-1 col-md-6">
            <div class="custom-switches-stacked mt-2">
                <div class="control-label">{{ __('Default Language') }}</div>
                <label class="custom-switch">
                    <input type="hidden" name="is_default" id="is_default" value="0"> 
                    <input type="checkbox" name="is_default" id="is_default" value="1" {{ $language_data->is_default == 1 ? 'checked' : '' }} class="custom-switch-input">
                    <span class="custom-switch-indicator"></span>   
                </label>
            </div>
        </div>

        <div class="col-sm-12 d-flex justify-content-end mt-3">
            <button type="submit" class="btn btn-primary me-1 mb-1">{{ __('Save') }}</button>
        </div>
    </div>
</form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script>
    // Validate JSON file inputs - prevent CSV and other non-JSON files
    $(document).ready(function() {
        // Validate App Translation JSON
        $('input[name="app_file"]').on('change', function() {
            validateJsonFile(this, 'app_file');
        });

        // Validate Panel Translation JSON
        $('input[name="panel_file"]').on('change', function() {
            validateJsonFile(this, 'panel_file');
        });

        // Validate Web Translation JSON
        $('input[name="web_file"]').on('change', function() {
            validateJsonFile(this, 'web_file');
        });

        function validateJsonFile(input, fieldName) {
            const file = input.files[0];
            const errorContainer = $(input).siblings('.json_error');
            
            // Create error container if it doesn't exist
            if (errorContainer.length === 0) {
                $(input).after('<div class="json_error text-danger" style="font-size: 0.875rem; margin-top: 0.25rem;"></div>');
            }
            
            const errorDiv = $(input).siblings('.json_error');
            errorDiv.text(''); // Clear previous errors
            
            if (!file) {
                return; // No file selected
            }

            // Check file extension - only allow .json
            const fileName = file.name.toLowerCase();
            const allowedExtensions = /\.json$/i;
            
            if (!allowedExtensions.test(fileName)) {
                errorDiv.text('Invalid file type. Only JSON files (.json) are allowed.');
                $(input).val(''); // Clear the file input
                
                // Show error toast using common function
                showSwalErrorToast('Only JSON files are allowed for ' + fieldName.replace('_', ' '), null, 3000);
                return false;
            }

            // Check file size (5MB max)
            const maxFileSize = 5 * 1024 * 1024; // 5MB
            if (file.size > maxFileSize) {
                errorDiv.text('File size exceeds 5MB limit.');
                $(input).val(''); // Clear the file input
                
                showSwalErrorToast('File size exceeds 5MB limit', null, 3000);
                return false;
            }

            // Validate JSON content if possible
            if (file.type && file.type !== 'application/json' && file.type !== 'text/json') {
                // Try to read and validate JSON content
                const reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        JSON.parse(e.target.result);
                        // Valid JSON
                    } catch (err) {
                        errorDiv.text('Invalid JSON file format. Please check the file content.');
                        $(input).val('');
                        
                        showSwalErrorToast('Invalid JSON file format', null, 3000);
                    }
                };
                reader.readAsText(file);
            }
            
            return true;
        }
    });
</script>
@endsection

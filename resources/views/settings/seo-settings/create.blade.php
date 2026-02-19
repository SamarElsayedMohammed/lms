@extends('layouts.app')

@section('title')
    {{ __('Create SEO Settings') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a href="{{ route('admin.seo-settings.index') }}" class="btn btn-secondary">
            <i class="fa fa-arrow-left"></i> {{ __('Back') }}
        </a>
    </div>
@endsection

@section('main')
    <div class="content-wrapper">
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">{{ __('Create SEO Settings') }}</h4>

                        <form action="{{ route('admin.seo-settings.store') }}" method="POST" enctype="multipart/form-data" 
                              class="create-form" data-success-function="formSuccessFunction">
                            @csrf
                            
                            <div class="row">
                                <!-- Language -->
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="language_id" class="form-label">{{ __('Language') }}</label>
                                    <select name="language_id" id="language_id" class="form-control" required>
                                        <option value="">{{ __('Select Language') }}</option>
                                        @foreach($languages as $language)
                                            <option value="{{ $language->id }}" {{ old('language_id') == $language->id ? 'selected' : '' }}>{{ $language->name }}</option>
                                        @endforeach
                                    </select>
                                    @if(isset($errors) && is_object($errors) && $errors->has('language_id'))
                                        <span class="text-danger">{{ $errors->first('language_id') }}</span>
                                    @endif
                                </div>

                                <!-- Page Type -->
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="page_type" class="form-label">{{ __('Page Type') }}</label>
                                    <select name="page_type" id="page_type" class="form-control" required>
                                        <option value="">{{ __('Select Page Type') }}</option>
                                        @foreach($pageTypes as $key => $label)
                                            <option value="{{ $key }}" {{ old('page_type') == $key ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @if(isset($errors) && is_object($errors) && $errors->has('page_type'))
                                        <span class="text-danger">{{ $errors->first('page_type') }}</span>
                                    @endif
                                </div>

                                <!-- Meta Title -->
                                <div class="form-group mandatory col-sm-12">
                                    <label for="meta_title" class="form-label">{{ __('Meta Title') }}</label>
                                    <input type="text" name="meta_title" id="meta_title" 
                                           class="form-control" placeholder="{{ __('Enter meta title') }}" 
                                           value="{{ old('meta_title') }}"
                                           maxlength="255" required>
                                    <small class="form-text text-muted">{{ __('Recommended: 50-60 characters') }}</small>
                                    @if(isset($errors) && is_object($errors) && $errors->has('meta_title'))
                                        <span class="text-danger">{{ $errors->first('meta_title') }}</span>
                                    @endif
                                </div>

                                <!-- Meta Description -->
                                <div class="form-group mandatory col-sm-12">
                                    <label for="meta_description" class="form-label">{{ __('Meta Description') }}</label>
                                    <textarea name="meta_description" id="meta_description" 
                                              class="form-control" rows="3" 
                                              placeholder="{{ __('Enter meta description') }}" 
                                              maxlength="500" required>{{ old('meta_description') }}</textarea>
                                    <small class="form-text text-muted">{{ __('Recommended: 150-160 characters') }}</small>
                                    @if(isset($errors) && is_object($errors) && $errors->has('meta_description'))
                                        <span class="text-danger">{{ $errors->first('meta_description') }}</span>
                                    @endif
                                </div>

                                <!-- Meta Keywords -->
                                <div class="form-group mandatory col-sm-12">
                                    <label for="meta_keywords" class="form-label">{{ __('Meta Keywords') }}</label>
                                    <textarea name="meta_keywords" id="meta_keywords" 
                                              class="form-control" rows="2" 
                                              placeholder="{{ __('Enter keywords separated by commas') }}" 
                                              required>{{ old('meta_keywords') }}</textarea>
                                    <small class="form-text text-muted">{{ __('Separate multiple keywords with commas') }}</small>
                                    @if(isset($errors) && is_object($errors) && $errors->has('meta_keywords'))
                                        <span class="text-danger">{{ $errors->first('meta_keywords') }}</span>
                                    @endif
                                </div>

                                <!-- Schema Markup -->
                                <div class="form-group mandatory col-sm-12">
                                    <label for="schema_markup" class="form-label">{{ __('Schema Markup') }}</label>
                                    <textarea name="schema_markup" id="schema_markup" 
                                              class="form-control" rows="5" 
                                              placeholder="{{ __('Enter schema markup (JSON-LD)') }}" 
                                              required>{{ old('schema_markup') }}</textarea>
                                    <small class="form-text text-muted">{{ __('Enter valid JSON-LD schema markup') }}</small>
                                    @if(isset($errors) && is_object($errors) && $errors->has('schema_markup'))
                                        <span class="text-danger">{{ $errors->first('schema_markup') }}</span>
                                    @endif
                                </div>

                                <!-- OG Image -->
                                <div class="form-group mandatory col-sm-12">
                                    <label for="og_image" class="form-label">{{ __('OG Image') }}</label>
                                    <input type="file" name="og_image" id="og_image" 
                                           class="form-control image" 
                                           accept="image/jpeg,image/jpg,image/png,image/gif,image/webp,image/svg+xml" 
                                           required>
                                    <small class="form-text text-muted">{{ __('Allowed formats: JPG, PNG, GIF, WebP, SVG (Max 2MB)') }}</small>
                                    <div class="preview-image-container mt-2" style="display: none;">
                                        <img src="" alt="Preview" class="preview-image img-thumbnail" 
                                             style="max-width: 200px; max-height: 200px;">
                                    </div>
                                    @if(isset($errors) && is_object($errors) && $errors->has('og_image'))
                                        <span class="text-danger">{{ $errors->first('og_image') }}</span>
                                    @endif
                                    @if(isset($errors) && is_object($errors) && $errors->has('duplicate'))
                                        <span class="text-danger">{{ $errors->first('duplicate') }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="form-group mt-4">
                                <button type="submit" class="btn btn-primary float-right">
                                    <i class="fa fa-save"></i> {{ __('Submit') }}
                                </button>
                                <a href="{{ route('admin.seo-settings.index') }}" class="btn btn-secondary float-right mr-2">
                                    <i class="fa fa-times"></i> {{ __('Cancel') }}
                                </a>
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
    // Image preview
    document.getElementById('og_image').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewContainer = document.querySelector('.preview-image-container');
                const previewImage = document.querySelector('.preview-image');
                previewImage.src = e.target.result;
                previewContainer.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    });

    // Custom success function to show green SweetAlert toast
    function formSuccessFunction(response) {
        if (response && response.redirect_url) {
            // Show success toast using common function
            showSwalSuccessToast(response.message || '{{ __("SEO settings created successfully") }}', '', 3000);
            // Redirect after showing toast
            setTimeout(() => {
                window.location.href = response.redirect_url;
            }, 1500);
        }
    }
</script>
@endsection

@extends('layouts.app')

@section('title')
    {{ __('Create Categories') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('categories.index') }}">← {{ __('Back to All Categories') }}</a>
    </div>
@endsection

@section('main')
    <section class="section">

        <form class="create-form" action="{{ route('categories.store') }}" method="POST" data-parsley-validate data-success-function="formSuccessFunction" data-pre-submit-function="validateCategoryImage"
            enctype="multipart/form-data">
            @method('POST')
            @csrf
            <div class="card border-0">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Add Category') }}</h5>
                </div>

                <div class="card-body">
                    <div class="row mt-4">
                        <div class="col-md-6 form-group mandatory">
                            <label for="category_name" class="form-label mandatory">{{ __('Name') }}</label>
                            <input type="text" name="name" id="category_name" class="form-control"
                            data-parsley-required="true">
                        </div>

                        <div class="col-md-6 form-group mandatory">
                            <label for="category_slug" class="form-label mandatory">
                                {{ __('Slug') }} <small class="text-muted">({{ __('English Only') }})</small>
                            </label>
                            <input type="text" name="slug" id="category_slug" class="form-control"
                            data-parsley-required="true">
                        </div>

                        <div class="col-md-6 form-group">
                            <label for="p_category" class="form-label">{{ __('Parent Category') }}</label>
                            <select name="parent_category_id" id="p_category" class="form-select select2"
                                data-placeholder="{{ __('Select Category') }}">
                                <option value="">{{ __('Select a Category') }}</option>
                                @include('categories.dropdowntree',['categories'=>$categories])
                            </select>
                        </div>

                        <div class="col-md-6 form-group mandatory" id="image-field-wrapper">
                            <label for="image" class="form-label mandatory" id="image-label">{{ __('Image') }}</label>
                            <input type="file" name="image" id="image" class="form-control" 
                                data-parsley-required="true" accept="image/jpeg,image/jpg,image/png,image/svg+xml,image/webp">
                            <small class="form-text text-muted">{{ __('Allowed formats: JPG, PNG, SVG, WebP (Max 7MB)') }}</small>
                            <div class="preview-image-container mt-2" style="display: none;">
                                <img src="" alt="Preview" class="preview-image img-thumbnail" style="max-width: 150px; max-height: 150px;">
                            </div>
                            <div class="img_error text-danger mt-1" style="color:#DC3545; font-weight: 500; min-height: 20px;"></div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="description" class="form-label">{{ __('Description') }}</label>
                            <textarea name="description" id="description" style="height: 150px;" class="form-control" cols="10" rows="5"></textarea>
                           
                            <div class="custom-switches-stacked mt-2">
                                <label class="custom-switch">
                                    <input type="hidden" name="status" value="0"> 
                                    <input type="checkbox" name="status" id="create-status" value="1" class="custom-switch-input" checked>
                                    <span class="custom-switch-indicator"></span>
                                    <span class="custom-switch-description" for="create-status">{{ __('Active') }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer text-end">
                    <button type="submit" class="btn btn-primary">      
                        {{ __('Save and Back') }}
                    </button>
                </div>
            </div>
        </form>
    </section> @endsection
@section('script')
    <script>
        function formSuccessFunction(response) {
            setTimeout(() => {
                window.location.href = "{{ route('categories.index') }}";
            }, 1500);
        }
        
        // Auto-generate slug from name field (handles both English and Gujarati)
        $(document).ready(function() {
            // Toggle image required based on parent category selection
            function toggleImageRequired() {
                const parentCategoryId = $('#p_category').val();
                const imageInput = $('#image');
                const imageLabel = $('#image-label');
                const imageWrapper = $('#image-field-wrapper');
                
                if (parentCategoryId && parentCategoryId !== '') {
                    // Subcategory - image not required
                    imageInput.removeAttr('data-parsley-required');
                    imageInput.removeAttr('required');
                    imageLabel.removeClass('mandatory');
                    imageWrapper.removeClass('mandatory');
                    imageLabel.find('.text-danger').remove();
                } else {
                    // Main category - image required
                    imageInput.attr('data-parsley-required', 'true');
                    imageInput.attr('required', 'required');
                    imageLabel.addClass('mandatory');
                    imageWrapper.addClass('mandatory');
                    // Remove any manually added asterisk since CSS handles it via .mandatory class
                    imageLabel.find('.text-danger').remove();
                }
            }
            
            // Listen for parent category changes
            $('#p_category').on('change', function() {
                toggleImageRequired();
            });
            
            // Initialize on page load
            toggleImageRequired();
            
            // Ensure slug is generated when name changes
            $('#category_name').on('input keyup', function() {
                let nameValue = $(this).val();
                if (nameValue) {
                    let slug = generateSlug(nameValue);
                    $('#category_slug').val(slug);
                } else {
                    $('#category_slug').val('');
                }
            });
            
            // Also generate on blur if slug is empty
            $('#category_name').on('blur', function() {
                if (!$('#category_slug').val() && $(this).val()) {
                    let slug = generateSlug($(this).val());
                    $('#category_slug').val(slug);
                }
            });
            
            // Image preview functionality using common validation function
            $('#image').on('change', function() {
                const imageInput = $(this);
                handleImageFileChange(imageInput, {
                    validation: {
                        allowedExtensions: ['jpg', 'jpeg', 'png', 'svg', 'webp'],
                        maxSizeMB: 7
                    },
                    onValid: function(file) {
                        const previewContainer = imageInput.siblings('.preview-image-container');
                        const previewImg = previewContainer.find('.preview-image');
                        if (previewContainer.length && previewImg.length) {
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                previewImg.attr('src', e.target.result);
                                previewContainer.show();
                            };
                            reader.readAsDataURL(file);
                        }
                    }
                });
            });
        });
        
        // Pre-submit validation function (called by global handler)
        function validateCategoryImage() {
            const imageInput = $('#image');
            const imgError = imageInput.closest('.form-group').find('.img_error');
            
            // First, check if there's a validation error (file size, file type, etc.)
            const validationError = imageInput.data('validation-error') || imageInput.attr('data-validation-error') || (imgError.length ? imgError.text().trim() : '');
            
            if (validationError) {
                // Show SweetAlert toast notification using common function
                showSwalErrorToast(validationError, null, 5000);
                
                // Scroll to error
                setTimeout(function() {
                    if (imgError.length && imgError.is(':visible')) {
                        $('html, body').animate({
                            scrollTop: imgError.offset().top - 150
                        }, 500);
                    }
                }, 100);
                
                return false; // Prevent form submission
            }
            
            // Check if parent category is selected (subcategory)
            const parentCategoryId = $('#p_category').val();
            
            // If subcategory is selected, image is not required
            if (parentCategoryId && parentCategoryId !== '') {
                return true; // Allow form submission without image
            }
            
            // For main category, image is required
            const imageFileInput = imageInput[0];
            if (!imageFileInput || !imageFileInput.files || imageFileInput.files.length === 0) {
                // Show SweetAlert toast notification using common function
                showSwalErrorToast('Please select a category image', null, 3000);
                
                // Scroll to image field
                $('html, body').animate({
                    scrollTop: imageInput.closest('.form-group').offset().top - 100
                }, 500);
                
                return false; // Prevent form submission
            }
            
            return true; // Allow form submission
        }
    </script>
@endsection

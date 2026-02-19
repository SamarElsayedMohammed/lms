@extends('layouts.app')

@section('title')
    {{ __('Edit Categories') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('categories.index') }}">← {{ __('Back to All Categories') }}</a>
    </div>
@endsection

@section('main')
    <section class="section">
        <form action="{{ route('categories.update', $category_data->id) }}" method="POST" data-parsley-validate
            enctype="multipart/form-data">
            @method('PUT')
            @csrf
            <input type="hidden" name="edit_data" value="{{ $category_data->id }}">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Edit Category') }}</h5>
                </div>

                <div class="card-body">
                    <div class="row mt-4">
                         <div class="col-md-6 form-group mandatory">
                            <label for="name" class="form-label mandatory">{{ __('Name') }}</label>
                            <input type="text" name="name" id="name" class="form-control" data-parsley-required="true" value="{{ $category_data->name }}">
                        </div>
                         <div class="col-md-6 form-group">
                            <label for="p_category" class="form-label">{{ __('Parent Category') }}</label>
                            <select name="parent_category_id" id="p_category" class="form-select select2"
                                data-placeholder="{{ __('Select Category') }}">
                                <option value="">{{ __('Select a Category') }}</option>
                                @if (isset($parent_category_data) && $parent_category_data->id)
                                    <option value="{{ $parent_category_data->id }}" selected>
                                        {{ $parent_category == '' ? 'Root' : $parent_category }}
                                    </option>
                                @endif
                                @include('categories.dropdowntree', [
                                    'categories' => $categories,
                                    'current_category_id' => $category_data->id,
                                ])
                            </select>
                        </div>
                        <div class="col-md-12 form-group mandatory" >
                            <label for="slug" class="form-label mandatory">{{ __('Slug') }} <small class="text-muted">({{ __('English Only') }}) </small></label>
                            <input type="text" name="slug" id="category_slug" class="form-control" data-parsley-required="true" value="{{ $category_data->slug }}">
                        </div>
                         <div class="col-md-6 form-group">
                            <label for="description" class="form-label">{{ __('Description') }}</label>
                            <textarea name="description" id="description" class="form-control" style="height: 150px;" cols="10" rows="5">{{ $category_data->description }}</textarea>
                        </div>
                        <div class="col-md-6 form-group maatory" id="image-field-wrapper">
                            <label for="image" class="form-label" id="image-label">{{ __('Image') }}</label>
                            <input type="file" name="image" id="image" class="form-control" 
                            data-parsley-required="true" accept="image/jpeg,image/jpg,image/png,image/svg+xml,image/webp">
                            <small class="form-text text-muted">{{ __('Allowed formats: JPG, PNG, SVG, WebP (Max 7MB)') }}</small>
                            <div class="preview-image-container mt-2" style="display: none;">
                                <img src="" alt="Preview" class="preview-image img-thumbnail" style="max-width: 150px; max-height: 150px;">
                            </div>
                            <div class="img_error text-danger mt-1" style="color:#DC3545; font-weight: 500; min-height: 20px;"></div>
                            <div id="image-current" class="mt-2">
                                @if($category_data->image_url)
                                    <small class="text-muted d-block mb-1">{{ __('Current Image') }}</small>
                                    <img src="{{ $category_data->image_url }}" alt="Current Image" class="img-thumbnail" style="max-width: 150px; max-height: 150px;">
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer text-end">
                    <button type="submit" class="btn btn-primary">{{ __('Save and Back') }}</button>
                </div>
            </div>
        </form>
    </section>
@endsection

@section('script')
<script>
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
                // Main category - image not required in edit (can keep existing)
                // But we can make it optional for edit form
                imageInput.removeAttr('data-parsley-required');
                imageInput.removeAttr('required');
                imageLabel.removeClass('mandatory');
                imageWrapper.removeClass('mandatory');
                imageLabel.find('.text-danger').remove();
            }
        }
        
        // Listen for parent category changes
        $('#p_category').on('change', function() {
            toggleImageRequired();
        });
        
        // Initialize on page load
        toggleImageRequired();
        
        // Auto-generate slug from name field (handles both English and Gujarati)
        // Generate slug when name changes
        $('#name').on('input keyup', function() {
            let nameValue = $(this).val();
            if (nameValue) {
                let slug = generateSlug(nameValue);
                $('#category_slug').val(slug);
            } else {
                $('#category_slug').val('');
            }
        });
        
        // Image preview functionality using common validation function
        $('#image').on('change', function() {
            const imageInput = $(this);
            const formGroup = imageInput.closest('.form-group');
            const imgError = formGroup.find('.img_error');
            const previewContainer = imageInput.siblings('.preview-image-container');
            const previewImg = previewContainer.find('.preview-image');
            const currentDiv = $('#image-current');
            
            // Clear previous errors
            if (imgError.length) {
                imgError.html('').css('display', 'none');
            }
            imageInput.removeData('validation-error');
            imageInput.removeAttr('data-validation-error');
            imageInput.removeClass('is-invalid');
            formGroup.removeClass('has-error');
            
            const file = imageInput[0].files[0];
            
            if (!file) {
                previewContainer.hide();
                if (currentDiv.length) {
                    currentDiv.show();
                }
                return;
            }
            
            // Validate file
            const validation = validateImageFile(file, {
                allowedExtensions: ['jpg', 'jpeg', 'png', 'svg', 'webp'],
                maxSizeMB: 7
            });
            
            if (!validation.valid) {
                // Display error message in error div - ensure it's visible
                if (imgError.length) {
                    imgError.html('<i class="fas fa-exclamation-circle mr-1"></i><strong>' + validation.error + '</strong>').css({
                        'display': 'block',
                        'color': '#DC3545',
                        'font-weight': '500',
                        'margin-top': '5px',
                        'visibility': 'visible',
                        'opacity': '1'
                    }).show();
                } else {
                    // Fallback: create error div if it doesn't exist
                    imageInput.after('<div class="img_error text-danger mt-1" style="color:#DC3545; font-weight: 500; min-height: 20px; display: block !important; visibility: visible !important;"><i class="fas fa-exclamation-circle mr-1"></i><strong>' + validation.error + '</strong></div>');
                }
                
                // Store error in data attribute
                imageInput.data('validation-error', validation.error);
                imageInput.attr('data-validation-error', validation.error);
                
                // Add error classes for visual feedback
                imageInput.addClass('is-invalid');
                formGroup.addClass('has-error');
                
                // Show SweetAlert toast notification
                if (typeof Swal !== 'undefined') {
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 5000,
                        timerProgressBar: true,
                        didOpen: (toast) => {
                            toast.addEventListener('mouseenter', Swal.stopTimer)
                            toast.addEventListener('mouseleave', Swal.resumeTimer)
                        }
                    });
                    
                    Toast.fire({
                        icon: 'error',
                        title: validation.error
                    });
                }
                
                imageInput.val('');
                previewContainer.hide();
                if (currentDiv.length) {
                    currentDiv.show();
                }
                
                // Scroll to error
                setTimeout(function() {
                    const errorElement = formGroup.find('.img_error');
                    if (errorElement.length) {
                        $('html, body').animate({
                            scrollTop: errorElement.offset().top - 150
                        }, 500);
                    }
                }, 100);
                
                return;
            }
            
            // File is valid - clear any previous errors
            if (imgError.length) {
                imgError.html('').css('display', 'none');
            }
            imageInput.removeData('validation-error');
            imageInput.removeAttr('data-validation-error');
            imageInput.removeClass('is-invalid');
            formGroup.removeClass('has-error');
            
            // Show preview
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.attr('src', e.target.result);
                previewContainer.show();
                if (currentDiv.length) {
                    currentDiv.hide(); // Hide current image when new one is selected
                }
            };
            reader.readAsDataURL(file);
        });
        
        // Prevent form submission if image error exists
        $('form').on('submit', function(e) {
            const imageInput = $('#image');
            const imgError = imageInput.closest('.form-group').find('.img_error');
            const validationError = imageInput.data('validation-error') || imageInput.attr('data-validation-error') || (imgError.length ? imgError.text().trim() : '');
            
            if (validationError) {
                e.preventDefault();
                e.stopPropagation();
                
                // Show SweetAlert error toast using common function
                showSwalErrorToast(validationError, '', 4000);
                
                // Scroll to error
                setTimeout(function() {
                    if (imgError.length && imgError.is(':visible')) {
                        $('html, body').animate({
                            scrollTop: imgError.offset().top - 150
                        }, 500);
                    }
                }, 100);
                
                return false;
            }
        });
    });
</script>
@endsection

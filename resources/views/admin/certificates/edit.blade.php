@extends('layouts.app')

@section('title')
    {{ __('Edit Certificate') }}
@endsection

@section('page-title')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
        <h1 class="mb-2 mb-md-0 flex-shrink-0">@yield('title'): <span class="d-block d-md-inline">{{ $certificate->name }}</span></h1>
        <div class="section-header-button w-100 w-md-auto" style="margin-left: auto;">
            <a href="{{ route('admin.certificates.index') }}" class="btn btn-secondary btn-block btn-sm-md">
                <i class="fas fa-arrow-left"></i> <span class="d-none d-sm-inline">{{ __('Back to Certificates') }}</span>
                <span class="d-sm-none">{{ __('Back') }}</span>
            </a>
        </div>
    </div>
@endsection

@section('main')
<div class="section">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form action="{{ route('admin.certificates.update', $certificate) }}" method="POST" enctype="multipart/form-data" class="certificate-edit-form" data-parsley-validate>
                        @csrf
                        @method('PUT')
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="name">Certificate Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control @error('name') is-invalid @enderror" 
                                           id="name" name="name" value="{{ old('name', $certificate->name) }}" required>
                                    @error('name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="type">Certificate Type <span class="text-danger">*</span></label>
                                    <select class="form-control @error('type') is-invalid @enderror" 
                                            id="type" name="type" required>
                                        <option value="course_completion" {{ old('type', $certificate->type) == 'course_completion' ? 'selected' : '' }}>
                                            Course Completion
                                        </option>
                                    </select>
                                    @error('type')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea class="form-control @error('description') is-invalid @enderror" 
                                      id="description" name="description" rows="3">{{ old('description', $certificate->description) }}</textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="title">Certificate Title</label>
                                    <input type="text" class="form-control @error('title') is-invalid @enderror" 
                                           id="title" name="title" value="{{ old('title', $certificate->title) }}" 
                                           placeholder="e.g., Certificate of Completion">
                                    @error('title')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="subtitle">Subtitle</label>
                                    <input type="text" class="form-control @error('subtitle') is-invalid @enderror" 
                                           id="subtitle" name="subtitle" value="{{ old('subtitle', $certificate->subtitle) }}" 
                                           placeholder="e.g., This is to certify that">
                                    @error('subtitle')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="background_image">Background Image</label>
                                    @if($certificate->background_image)
                                    <div class="mb-2">
                                        <img src="{{ $certificate->background_image_url }}" alt="Current Background" 
                                             class="img-thumbnail" style="max-width: 200px; max-height: 150px;">
                                        <p class="text-muted small">Current background image</p>
                                    </div>
                                    @endif
                                    <input type="file" class="form-control-file @error('background_image') is-invalid @enderror" 
                                           id="background_image" name="background_image" accept="image/*">
                                    <small class="form-text text-muted">Recommended size: 1200x800px or similar aspect ratio</small>
                                    @error('background_image')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="signature_image">Signature Image</label>
                                    @if($certificate->signature_image)
                                    <div class="mb-2">
                                        <img src="{{ $certificate->signature_image_url }}" alt="Current Signature" 
                                             class="img-thumbnail" style="max-width: 200px; max-height: 100px;">
                                        <p class="text-muted small">Current signature image</p>
                                    </div>
                                    @endif
                                    <input type="file" class="form-control-file @error('signature_image') is-invalid @enderror" 
                                           id="signature_image" name="signature_image" accept="image/*">
                                    <small class="form-text text-muted">Recommended size: 200x100px</small>
                                    @error('signature_image')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="signature_text">Signature Text</label>
                            <input type="text" class="form-control @error('signature_text') is-invalid @enderror" 
                                   id="signature_text" name="signature_text" value="{{ old('signature_text', $certificate->signature_text) }}" 
                                   placeholder="e.g., Director of Education">
                            @error('signature_text')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="is_active" name="is_active" 
                                       value="1" {{ old('is_active', $certificate->is_active) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> <span class="d-none d-sm-inline">Update Certificate</span>
                                <span class="d-sm-none">Update</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Preview image functionality
    document.getElementById('background_image').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                // You can add image preview functionality here
                console.log('Background image selected:', file.name);
            };
            reader.readAsDataURL(file);
        }
    });

    document.getElementById('signature_image').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                // You can add image preview functionality here
                console.log('Signature image selected:', file.name);
            };
            reader.readAsDataURL(file);
        }
    });
    
    // Custom handler for certificate edit form - avoids conflict with global edit-form handler
    $('form.certificate-edit-form').on('submit', function(e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        
        let formElement = $(this);
        let submitButtonElement = $(this).find(':submit');
        let url = $(this).attr('action');
        let data = new FormData(this);
        
        function successCallback(response) {
            console.log('Certificate update success response:', response);
            formElement.parsley().reset();
            
            // Check for redirect_url in response
            let redirectUrl = response.redirect_url || "{{ route('admin.certificates.index') }}";
            
            setTimeout(() => {
                window.location.href = redirectUrl;
            }, 1500);
        }
        
        formAjaxRequest('PUT', url, data, formElement, submitButtonElement, successCallback);
        return false;
    });
});
</script>
@endpush

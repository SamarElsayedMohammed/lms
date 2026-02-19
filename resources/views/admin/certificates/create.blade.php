@extends('layouts.app')

@section('title')
    {{ __('Create Certificate') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a href="{{ route('admin.certificates.index') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> {{ __('Back to Certificates') }}
        </a>
    </div>
@endsection

@section('main')
<div class="section">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h4>{{ __('Create New Certificate') }}</h4>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.certificates.store') }}" method="POST" enctype="multipart/form-data" class="certificate-create-form" data-parsley-validate>
                        @csrf
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="name">Certificate Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control @error('name') is-invalid @enderror" 
                                           id="name" name="name" value="{{ old('name') }}" required>
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
                                        <option value="course_completion" {{ old('type', 'course_completion') == 'course_completion' ? 'selected' : '' }}>
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
                                      id="description" name="description" rows="3">{{ old('description') }}</textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="title">Certificate Title</label>
                                    <input type="text" class="form-control @error('title') is-invalid @enderror" 
                                           id="title" name="title" value="{{ old('title') }}" 
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
                                           id="subtitle" name="subtitle" value="{{ old('subtitle') }}" 
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
                                   id="signature_text" name="signature_text" value="{{ old('signature_text') }}" 
                                   placeholder="e.g., Director of Education">
                            @error('signature_text')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="is_active" name="is_active" 
                                       value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Create Certificate
                            </button>
                            <a href="{{ route('admin.certificates.index') }}" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

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
    
    // Custom handler for certificate create form - avoids conflict with global create-form handler
    $('form.certificate-create-form').on('submit', function(e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        
        let formElement = $(this);
        let submitButtonElement = $(this).find(':submit');
        let url = $(this).attr('action');
        let data = new FormData(this);
        
        function successCallback(response) {
            console.log('Certificate create success response:', response);
            if (!$(formElement).hasClass('create-form-without-reset')) {
                formElement[0].reset();
                $(".select2").val("").trigger('change');
            }
            
            // Check for redirect_url in response
            let redirectUrl = response.redirect_url || "{{ route('admin.certificates.index') }}";
            
            setTimeout(() => {
                window.location.href = redirectUrl;
            }, 1500);
        }
        
        formAjaxRequest('POST', url, data, formElement, submitButtonElement, successCallback);
        return false;
    });
});
</script>
@endpush

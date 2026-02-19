@extends('layouts.app')
@section('title')
    {{ __('System Update') }}
@endsection
@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
    </div>
@endsection

@section('main')
    <div class="content-wrapper">
        <!-- System Update Form -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <form action="{{ route('settings.system-update.update') }}" method="POST" enctype="multipart/form-data" class="create-form" data-success-function="systemUpdateSuccess">
                    @csrf
                    <div class="card">
                        <div class="card-header">
                            <div class="divider">
                                <div class="divider-text">
                                    <h4 class="card-title">{{ __('System Update') }}</h4>
                                </div>
                            </div>
                        </div>
                        <div class="card-body mt-4">
                            <div class="row">
                                <!-- System Version -->
                                <div class="col-md-12 mb-4">
                                    <div class="form-group">
                                        <label class="form-label">{{ __('System Version') }} :</label>
                                        <span class="text-danger font-weight-bold">{{ $settings['system_version'] ?? '1.0.0' }}</span>
                                    </div>
                                </div>

                                <!-- Purchase Code -->
                                <div class="col-md-6">
                                    <div class="form-group mandatory">
                                        <label for="purchase_code" class="form-label">{{ __('Purchase Code') }}</label>
                                        <input type="text" name="purchase_code" class="form-control" id="purchase_code" value="" placeholder="{{ __('Enter Purchase Code') }}" required>
                                    </div>
                                </div>

                                <!-- Update File -->
                                <div class="col-md-6">
                                    <div class="form-group mandatory">
                                        <label for="update_file" class="form-label">{{ __('Update File') }}</label>
                                        <div class="input-group">
                                            <div class="custom-file">
                                                <input type="file" name="update_file" class="custom-file-input" id="update_file" accept=".zip" required>
                                                <label class="custom-file-label" for="update_file">{{ __('Choose File') }}</label>
                                            </div>
                                        </div>
                                        <small class="form-text text-muted">{{ __('Please upload the update zip file') }}</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer text-right">
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Update file input label when file is selected
    document.getElementById('update_file').addEventListener('change', function(e) {
        var fileName = e.target.files[0] ? e.target.files[0].name : '{{ __('Choose File') }}';
        e.target.nextElementSibling.textContent = fileName;
    });

    // Custom success function for system update - show toast in top right
    function systemUpdateSuccess(response) {
        if (response && !response.error) {
            // Show success toast in top right
            if (typeof showSwalSuccessToast === 'function') {
                showSwalSuccessToast(response.message || '{{ __("System updated successfully") }}', '', 3000, function() {
                    // Refresh page after toast closes
                    window.location.reload();
                });
            } else if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    text: response.message || '{{ __("System updated successfully") }}',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer);
                        toast.addEventListener('mouseleave', Swal.resumeTimer);
                    }
                }).then(function() {
                    // Refresh page after toast disappears
                    window.location.reload();
                });
            } else {
                // Fallback: just reload if SweetAlert is not available
                setTimeout(function() {
                    window.location.reload();
                }, 1000);
            }
        }
    }
</script>
@endpush

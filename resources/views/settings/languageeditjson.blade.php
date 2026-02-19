    @extends('layouts.app')

    @section('title')
        {{ __('Edit Language') }} - {{ $language->name }} ({{ strtoupper($type) }})
    @endsection

    @section('page-title')
        <h1 class="mb-0">{{ __('Edit Language') }} - {{ $language->name }} ({{ strtoupper($type) }})</h1>
        <div class="section-header-button ml-auto">
            <a href="{{ route('settings.language') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> {{ __('Back to Languages') }}
            </a>
        </div> @endsection

    @section('main')
    <section class="section">
            <div class="row">
                <div class="col-12">
                    <!-- Action Buttons -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <h5 class="mb-0">{{ __('Language Translation Tools') }}</h5>
                                    <small class="text-muted">{{ __('Manage translations for') }} {{ $language->name }} ({{ $type }})</small>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex justify-content-end align-items-center">
                                        <button type="button" class="btn btn-info mr-2" id="auto-translate-btn" style="white-space: nowrap; flex-shrink: 0;">
                                        <i class="fas fa-magic"></i> {{ __('Auto Translate') }}
                                    </button>
                                        <button type="button" class="btn btn-warning" id="refresh-page-btn" style="white-space: nowrap; flex-shrink: 0;">
                                        <i class="fas fa-sync"></i> {{ __('Refresh') }}
                                    </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Translation Form -->
                    <form action="{{ route('updatelanguage', ['id' => $language->id, 'type' => $type]) }}" method="POST" enctype="multipart/form-data" class="editlanguage-form"> @csrf
                        @method('PUT')
        <div class="card">
                            <div class="card-header">
                                <h4>{{ __('Translation Fields') }}</h4>
                                <div class="card-header-action">
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="search-translations" placeholder="{{ __('Search translations...') }}">
                                        <div class="input-group-append">
                                            <button class="btn btn-primary" type="button">
                                                <i class="fas fa-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row" id="translation-fields"> @foreach($enLabels as $key => $value) 
                                        @if(is_string($value))
                                        <div class="col-md-4 translation-field" data-key="{{ $key }}">
                                            <div class="form-group">
                                                <label for="value-{{ $loop->index }}" class="form-label">
                                                    <strong>{{ $key }}</strong>
                                                    <small class="text-muted d-block">{{ __('English') }}: {{ $value }}</small>
                                                </label>
                                                <input type="text" 
                                                       class="form-control" 
                                                       id="value-{{ $loop->index }}" 
                                                       name="values[]" 
                                                       value="{{ isset($targetLabels[$key]) && is_string($targetLabels[$key]) && !empty(trim($targetLabels[$key])) && trim($targetLabels[$key]) !== trim($value) ? $targetLabels[$key] : '' }}" 
                                                       placeholder="{{ $value }}"
                                                       data-original="{{ htmlspecialchars($value, ENT_QUOTES, 'UTF-8') }}"
                                                       data-key="{{ $key }}">
                                            </div>
                                        </div>
                                        @endif
                                        @endforeach </div>
                                
                                <div class="row mt-4">
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="badge badge-info">{{ count($enLabels) }} {{ __('translations') }}</span>
                                                <span class="badge badge-success" id="translated-count">{{ __('0 translated') }}</span>
                                                <span class="badge badge-warning" id="missing-count">{{ count($enLabels) }} {{ __('missing') }}</span>
                                            </div>
                                            <div>
                                                <button type="submit" class="btn btn-primary btn-lg">
                                                    <i class="fas fa-save"></i> {{ __('Save All Changes') }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- Auto Translate Modal -->
        <div class="modal fade" id="autoTranslateModal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Auto Translate') }}</h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span> {{ __('&times;') }} </span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p>{{ __('This will automatically translate all missing or empty translation fields.') }}</p>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            {{ __('Note: This uses a basic translation system. For production use, consider integrating with Google Translate API.') }}
                        </div>
                        <p><strong>{{ __('Language') }}:</strong> {{ $language->name }} ({{ $language->code }})</p>
                        <p><strong>{{ __('Type') }}:</strong> {{ strtoupper($type) }}</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="button" class="btn btn-info" id="confirm-auto-translate">
                            <i class="fas fa-magic"></i> {{ __('Start Auto Translation') }}
                        </button>
                    </div>
                </div>
            </div>
        </div> @endsection

    @section('script')
    <script>
        $(document).ready(function() {
            // Update translation counts
            function updateCounts() {
                let translated = 0;
                let missing = 0;
                
                $('.translation-field').each(function() {
                    const input = $(this).find('input[type="text"]');
                    const value = input.val().trim();
                    const original = input.data('original') || '';
                    const originalTrimmed = original.trim();
                    
                    // Count as translated if:
                    // 1. Value is not empty
                    // 2. Value is different from the original English value
                    // 3. Value is not just whitespace
                    if (value && value !== originalTrimmed && value.length > 0) {
                        translated++;
                    } else {
                        missing++;
                    }
                });
                
                const total = translated + missing;
                $('#translated-count').text(translated + ' {{ __("translated") }}');
                $('#missing-count').text(missing + ' {{ __("missing") }}');
            }

            // Search functionality
            $('#search-translations').on('keyup', function() {
                const searchTerm = $(this).val().toLowerCase();
                $('.translation-field').each(function() {
                    const key = $(this).data('key').toLowerCase();
                    const value = $(this).find('input').val().toLowerCase();
                    
                    if (key.includes(searchTerm) || value.includes(searchTerm)) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // Update counts on input change
            $('.translation-field input').on('input', updateCounts);

            // Auto translate button - direct execution without modal
            $('#auto-translate-btn').on('click', function() {
                const button = $(this);
                button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{ __("Translating...") }}');
                
                $.ajax({
                    url: '{{ route("language.auto-translate", ["id" => $language->id, "type" => $type, "locale" => $language->code]) }}',
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        locale: '{{ $language->code }}'
                    },
                    success: function(response) {
                        console.log('Auto translate response:', response);
                        // Check for success: response.error === false or response.status === true
                        if (!response.error || response.status === true) {
                            // Format the message - replace newlines with HTML breaks for better display
                            let message = response.message || '{{ __("Translations have been successfully generated and saved.") }}';
                            // Convert newlines to <br> for HTML display
                            let formattedMessage = message.replace(/\n/g, '<br>');
                            
                            // Show success message using common function
                            showSwalSuccessToastHTML(formattedMessage, '', 6000, '450px', function() {
                                // Reload page after toast disappears
                                window.location.reload();
                            });
                        } else {
                            // Show error message using common function
                            showSwalErrorToast(
                                response.message || '{{ __("An error occurred during translation.") }}',
                                '',
                                5000
                            );
                            button.prop('disabled', false).html('<i class="fas fa-magic"></i> {{ __("Auto Translate") }}');
                        }
                    },
                    error: function(xhr) {
                        // Show error message with SweetAlert2 in top right
                        let errorMessage = '{{ __("An error occurred during translation.") }}';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMessage = xhr.responseJSON.message;
                        }
                        showSwalErrorToast(errorMessage, '', 5000);
                        button.prop('disabled', false).html('<i class="fas fa-magic"></i> {{ __("Auto Translate") }}');
                    }
                });
            });


            // Refresh page button
            $('#refresh-page-btn').on('click', function() {
                location.reload();
            });

            // Initialize counts after a short delay to ensure DOM is ready
            setTimeout(function() {
                updateCounts();
            }, 100);
            
            // Also update counts when inputs are loaded
            $(window).on('load', function() {
                updateCounts();
            });

            // Override the default form submission handler for this form
            $('.editlanguage-form').off('submit').on('submit', function(e) {
                e.preventDefault();
                const formElement = $(this);
                const submitButton = formElement.find('button[type="submit"]');
                const originalButtonText = submitButton.html();
                
                // Disable submit button and show loading
                submitButton.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{ __("Saving...") }}');
                
                const url = formElement.attr('action');
                const formData = new FormData(formElement[0]);
                
                $.ajax({
                    url: url,
                    method: 'PUT',
                    data: formData,
                    processData: false,
                    contentType: false,
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || $('input[name="_token"]').val()
                    },
                    success: function(response) {
                        console.log('Save response:', response);
                        // Show success message with SweetAlert2 in top right
                        Swal.fire({
                            icon: 'success',
                            title: '{{ __("Success") }}',
                            text: response.message || '{{ __("Translations saved successfully.") }}',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 3000,
                            timerProgressBar: true,
                            didOpen: (toast) => {
                                toast.addEventListener('mouseenter', Swal.stopTimer)
                                toast.addEventListener('mouseleave', Swal.resumeTimer)
                            }
                        }).then(function() {
                            // Reload page after toast disappears
                            window.location.reload();
                        });
                    },
                    error: function(xhr) {
                        console.error('Save error:', xhr);
                        let errorMessage = '{{ __("Failed to save translations.") }}';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMessage = xhr.responseJSON.message;
                        }
                        // Show error message with SweetAlert2 in top right
                        Swal.fire({
                            icon: 'error',
                            title: '{{ __("Error") }}',
                            text: errorMessage,
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 5000,
                            timerProgressBar: true
                        });
                        // Re-enable submit button
                        submitButton.prop('disabled', false).html(originalButtonText);
                    }
                });
            });
        });
    </script>
    @endsection

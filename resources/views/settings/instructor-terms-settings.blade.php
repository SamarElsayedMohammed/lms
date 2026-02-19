<!DOCTYPE html>
@extends('layouts.app')

@section('title')
    {{ __('Instructor Terms & Conditions') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
@endsection

@section('main')
    <div class="content-wrapper">
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <form action="{{ route('settings.instructor-terms.update') }}" method="POST" class="create-form" data-success-function="formSuccessFunction" id="instructorTermsForm"> 
                            @csrf 

                            <div class="row">
                                <div class="col-12 mb-4">
                                    <div class="form-group mandatory">  
                                        <label>{{ __('Individual Instructor Terms & Conditions') }}</label>
                                        <textarea name="individual_instructor_terms" id="tinymce-individual" class="form-control tinymce-editor" required>{{ $settings['individual_instructor_terms'] ?? '' }}</textarea>
                                    </div>
                                </div>

                                <div class="col-12 mb-4">
                                    <div class="form-group mandatory">
                                        <label>{{ __('Team Instructor Terms & Conditions') }}</label>
                                        <textarea name="team_instructor_terms" id="tinymce-team" class="form-control tinymce-editor" required>{{ $settings['team_instructor_terms'] ?? '' }}</textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary">{{ __('Save Settings') }}</button>
                                </div>
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
    function formSuccessFunction() {
        window.location.reload();
    }
    
    // Initialize TinyMCE for this page with proper standards mode handling
    (function() {
        function initTinyMCE() {
            // Check document mode first
            var compatMode = document.compatMode;
            var docType = document.doctype;
            
            console.log('Document compatMode:', compatMode);
            console.log('Document doctype:', docType ? docType.name + ' ' + docType.publicId + ' ' + docType.systemId : 'missing');
            
            if (compatMode !== 'CSS1Compat') {
                console.error('Document is NOT in standards mode! Current mode: ' + compatMode);
                console.error('This will cause TinyMCE initialization to fail.');
                // Try to continue anyway - sometimes it still works
            }
            
            if (typeof tinymce === 'undefined') {
                console.error('TinyMCE library not loaded');
                return;
            }
            
            if (typeof jQuery === 'undefined') {
                console.error('jQuery not loaded');
                return;
            }
            
            // Remove any existing instances first
            try {
                var editor1 = tinymce.get('tinymce-individual');
                if (editor1) {
                    tinymce.remove(editor1);
                }
                var editor2 = tinymce.get('tinymce-team');
                if (editor2) {
                    tinymce.remove(editor2);
                }
            } catch(e) {
                console.warn('Error removing existing instances:', e);
            }
            
            // Check if textareas exist
            var $individual = $('#tinymce-individual');
            var $team = $('#tinymce-team');
            
            if ($individual.length === 0 || $team.length === 0) {
                console.warn('TinyMCE textareas not found');
                return;
            }
            
            // Initialize TinyMCE with error handling
            try {
                tinymce.init({
                    selector: '#tinymce-individual, #tinymce-team',
                    height: 400,
                    menubar: false,
                    plugins: [
                        'advlist autolink lists link image charmap print preview anchor',
                        'searchreplace visualblocks code fullscreen',
                        'insertdatetime media table paste code help wordcount'
                    ],
                    toolbar: 'undo redo | formatselect | bold italic backcolor | \
                    alignleft aligncenter alignright alignjustify | \
                    bullist numlist outdent indent | removeformat | help',
                    content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px }',
                    schema: 'html5',
                    doctype: '<!DOCTYPE html>',
                    forced_root_block: 'p',
                    setup: function (editor) {
                        editor.on('change', function () {
                            editor.save();
                        });
                    },
                    init_instance_callback: function(editor) {
                        console.log('TinyMCE editor initialized successfully:', editor.id);
                    }
                });
            } catch(e) {
                console.error('Error initializing TinyMCE:', e);
                alert('Failed to initialize editor. Please check browser console for details.');
            }
        }
        
        // Try multiple initialization strategies
        if (document.readyState === 'complete') {
            setTimeout(initTinyMCE, 100);
        } else {
            // Wait for window load event
            window.addEventListener('load', function() {
                setTimeout(initTinyMCE, 200);
            });
            
            // Also try on DOMContentLoaded
            if (document.addEventListener) {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(initTinyMCE, 300);
                });
            }
        }
    })();
</script>
@endpush

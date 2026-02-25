@extends('layouts.app')

@section('title')
{{ __('Create Course') }}
@endsection

@section('page-title')
<h1 class="mb-0">@yield('title')</h1>
<div class="section-header-button ml-auto">
    <a class="btn btn-primary" href="{{ route('courses.index') }}">← {{ __('Back to All Courses') }}</a>
</div> @endsection

@section('main')
<div class="content-wrapper">
    <div class="row">
        <div class="col-md-12 grid-margin stretch-card search-container">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">
                        {{ __('Create Course') }}
                    </h4>
                    {{-- Start Form --}}
                    <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('courses.store') }}"
                        data-success-function="formSuccessFunction" data-pre-submit-function="validateVideoFileSize"
                        data-parsley-validate enctype="multipart/form-data"> @csrf
                        <div class="row">
                            {{-- Name --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Title') }} <span class="text-danger"> {{ __('*') }} </span></label>
                                <input type="text" name="title" id="title" placeholder="{{ __('Title') }}"
                                    class="form-control" required>
                            </div>

                            {{-- Short Description --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Short Description') }}</label>
                                <textarea name="short_description" id="short_description" class="form-control"
                                    placeholder="{{ __('Short Description') }}"></textarea>
                            </div>

                            {{-- Thumbnail --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Thumbnail') }} <span class="text-danger">*</span></label>
                                <input type="file" name="thumbnail" id="thumbnail" class="form-control" accept="image/*"
                                    onchange="previewThumbnail(this)">
                            </div>

                            {{-- Intro Video --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Intro Video') }}</label>
                                <div class="mb-2">
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="intro_type_none" name="intro_video_type" value=""
                                            class="custom-control-input" checked>
                                        <label class="custom-control-label" for="intro_type_none">{{ __('None')
                                            }}</label>
                                    </div>
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="intro_type_file" name="intro_video_type" value="file"
                                            class="custom-control-input">
                                        <label class="custom-control-label" for="intro_type_file">{{ __('Upload File')
                                            }}</label>
                                    </div>
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="intro_type_url" name="intro_video_type" value="url"
                                            class="custom-control-input">
                                        <label class="custom-control-label" for="intro_type_url">{{ __('Video URL')
                                            }}</label>
                                    </div>
                                </div>
                                <div id="intro_video_file_wrapper" style="display:none;">
                                    <input type="file" name="intro_video" id="intro_video" class="form-control"
                                        accept="video/*">
                                    <small class="form-text text-muted">{{ __('Maximum file size:') }} <span
                                            id="max-video-size">{{ $maxVideoSizeMB ?? 100 }}</span> MB</small>
                                </div>
                                <div id="intro_video_url_wrapper" style="display:none;">
                                    <input type="url" name="intro_video_url" id="intro_video_url" class="form-control"
                                        placeholder="{{ __('Enter video URL (YouTube, Vimeo, etc.)') }}">
                                    <small class="form-text text-muted">{{ __('Paste a valid video link') }}</small>
                                </div>
                                <div id="intro_video_error" class="alert alert-danger mt-2" role="alert"
                                    style="display:none;">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <span id="intro_video_error_text"></span>
                                </div>
                            </div>

                            {{-- Content Structure --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Content Structure') }} <span class="text-danger">*</span>
                                    <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip"
                                        title="{{ __('Choose how to organize your course content') }}"></i>
                                </label>
                                <div class="mt-1">
                                    <div class="custom-control custom-radio mb-2">
                                        <input type="radio" id="structure_chapters" name="content_structure"
                                            value="chapters" class="custom-control-input" checked>
                                        <label class="custom-control-label" for="structure_chapters">
                                            <i class="fas fa-layer-group mr-1 text-primary"></i> <strong>{{ __('Chapters
                                                & Lessons') }}</strong>
                                            <br><small class="text-muted">{{ __('Group lessons inside chapters')
                                                }}</small>
                                        </label>
                                    </div>
                                    <div class="custom-control custom-radio">
                                        <input type="radio" id="structure_lessons" name="content_structure"
                                            value="lessons" class="custom-control-input">
                                        <label class="custom-control-label" for="structure_lessons">
                                            <i class="fas fa-list mr-1 text-success"></i> <strong>{{ __('Direct
                                                Lessons') }}</strong>
                                            <br><small class="text-muted">{{ __('Add lessons directly without chapters')
                                                }}</small>
                                        </label>
                                    </div>
                                </div>
                            </div>


                            {{-- Level --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Level') }} <span class="text-danger"> {{ __('*') }} </span></label>
                                <select name="level" id="level" class="form-control" required>
                                    <option value="beginner">{{ __('Beginner') }}</option>
                                    <option value="intermediate">{{ __('Intermediate') }}</option>
                                    <option value="advanced">{{ __('Advanced') }}</option>
                                </select>
                            </div>

                            {{-- Course Type --}}
                            <div class="form-group mandatory col-sm-12 col-md-2">
                                <label class="d-block form-label">{{ __('Course Type') }}</label>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="course_type_free" name="course_type" value="free"
                                        class="custom-control-input" checked>
                                    <label class="custom-control-label" for="course_type_free">{{ __('Free') }}</label>
                                </div>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="course_type_paid" name="course_type" value="paid"
                                        class="custom-control-input">
                                    <label class="custom-control-label" for="course_type_paid">{{ __('Paid') }}</label>
                                </div>
                            </div>



                            {{-- Certificate Toggle (Only for Free Courses) --}}
                            <div class="form-group col-sm-12 col-md-3 certificate-toggle-field" style="display: none;">
                                <div class="control-label">
                                    {{ __('Certificate Available') }}
                                    <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip"
                                        data-placement="top"
                                        title="{{ __('Enable certificate generation for this free course. Students can get a certificate by paying the specified fee.') }}"></i>
                                </div>
                                <div class="custom-switches-stacked mt-2">
                                    <label class="custom-switch">
                                        <input type="checkbox" class="custom-switch-input" id="certificate_enabled"
                                            name="certificate_enabled" value="1">
                                        <span class="custom-switch-indicator"></span>
                                        <span class="custom-switch-description certificate-enabled-text">{{ __('No')
                                            }}</span>
                                    </label>
                                </div>
                            </div>

                            {{-- Certificate Fee (Only if Certificate is Enabled) --}}
                            <div class="form-group col-sm-12 col-md-3 certificate-fee-field" style="display: none;">
                                <label class="form-label">
                                    {{ __('Certificate Fee') }}
                                    <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip"
                                        data-placement="top"
                                        title="{{ __('This fee will be charged to students who want to get a certificate for completing this free course') }}"></i>
                                </label>
                                <input type="number" name="certificate_fee" id="certificate_fee" step="0.01" min="0"
                                    placeholder="{{ __('Certificate Fee') }}" class="form-control">
                            </div>


                            {{-- Category --}}
                            <div class="col-md-6 form-group mandatory">
                                <label for="category_id" class="form-label">{{ __('Category') }}</label>
                                <select name="category_id" id="category_id" class="form-control" required>
                                    <option value="">{{ __('Select Category') }}</option>
                                    @foreach($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Language --}}
                            <input type="hidden" name="language_id" value="{{ $course_languages->first()->id ?? '' }}">

                            {{-- Course Learnings --}}
                            <div class="form-group col-12">
                                <label class="form-label">{{ __('What will students learn?') }}</label>
                                <div id="course-learnings-repeater">
                                    <div class="input-group mb-2">
                                        <input type="text" name="learnings_data[0][learning]" class="form-control"
                                            placeholder="{{ __('Enter learning objective') }}">
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-success btn-sm"
                                                onclick="addLearning()">+</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Course Requirements --}}
                            <div class="form-group col-12">
                                <label class="form-label">{{ __('Course Requirements') }}</label>
                                <div id="course-requirements-repeater">
                                    <div class="input-group mb-2">
                                        <input type="text" name="requirements_data[0][requirement]" class="form-control"
                                            placeholder="{{ __('Enter requirement') }}">
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-success btn-sm"
                                                onclick="addRequirement()">+</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Course Tags --}}
                            <div class="form-group col-12">
                                <label class="form-label">{{ __('Course Tags') }}</label>
                                <input type="text" name="course_tags" id="course_tags" class="form-control inputtags"
                                    placeholder="{{ __('Enter tags separated by commas') }}">
                            </div>

                            <div>
                                <hr>
                            </div>

                            {{-- SEO Meta Tags Section --}}
                            <div class="form-group col-12">
                                <h5 class="mb-3 text-primary"><i class="fas fa-tags mr-2"></i>{{ __('SEO Meta Tags') }}
                                </h5>
                            </div>

                            {{-- Meta Title --}}
                            <div class="form-group col-12">
                                <label for="meta_title" class="form-label">{{ __('Meta Title') }}</label>
                                <input type="text" name="meta_title" id="meta_title" class="form-control"
                                    placeholder="{{ __('Enter meta title for SEO') }}" maxlength="60">
                                <small class="form-text text-muted"><i class="fas fa-info-circle mr-1"></i>{{ __('SEO
                                    title for search engines (recommended: 50-60 characters)') }}</small>
                            </div>

                            {{-- Meta Description --}}
                            <div class="form-group col-12">
                                <label for="meta_description" class="form-label">{{ __('Meta Description') }}</label>
                                <textarea name="meta_description" id="meta_description" class="form-control"
                                    placeholder="{{ __('Enter meta description for SEO') }}" rows="3"
                                    maxlength="160"></textarea>
                                <small class="form-text text-muted"><i class="fas fa-info-circle mr-1"></i>{{ __('SEO
                                    description for search engines (recommended: 150-160 characters)') }}</small>
                            </div>

                            {{-- Meta Keywords --}}
                            <div class="form-group col-12">
                                <label for="meta_keywords" class="form-label">{{ __('Meta Keywords') }}</label>
                                <textarea name="meta_keywords" id="meta_keywords" class="form-control"
                                    placeholder="{{ __('Enter keywords separated by commas (e.g., course, online, learning)') }}"
                                    rows="2"></textarea>
                                <small class="form-text text-muted"><i class="fas fa-info-circle mr-1"></i>{{
                                    __('Keywords for SEO (separate multiple keywords with commas)') }}</small>
                            </div>

                            <div>
                                <hr>
                            </div>
                            {{-- Sequential Chapter Access --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <div class="control-label">
                                    {{ __('Sequential Chapter Access') }}
                                    <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip"
                                        data-placement="top"
                                        title="{{ __('If enabled, students must complete chapters in order. If disabled, they can access any chapter freely.') }}"></i>
                                </div>
                                <div class="custom-switches-stacked mt-2">
                                    <label class="custom-switch">
                                        <input type="checkbox" class="custom-switch-input" id="sequential_access"
                                            name="sequential_access" value="1" checked>
                                        <span class="custom-switch-indicator"></span>
                                        <span class="custom-switch-description sequential-access-text">{{ __('Sequential
                                            (Step by step)') }}</span>
                                    </label>
                                </div>
                            </div>

                        </div>
                        <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit"
                            value="{{ __('Create Course') }}">
                    </form>
                </div>
            </div>
        </div>
    </div>
</div> @endsection

@section('script')
<script>
    // بعد إنشاء الدورة يتم التوجيه لصفحة التعديل لإضافة الفصول والدروس
    window.formSuccessFunction = function(response) {
        if (response && response.data && response.data.course_id) {
            window.location.href = "{{ url('courses') }}/" + response.data.course_id + "/edit";
            return;
        }
        if (response && response.message) {
            if (typeof window.formSuccessFunctionDefault === 'function') {
                window.formSuccessFunctionDefault(response);
            } else {
                console.log('Success:', response.message);
            }
        }
    };

    $(document).ready(function () {
        // Sequential Access Toggle
        const $sequentialAccessToggle = $('#sequential_access');
        const $sequentialAccessText = $('.sequential-access-text');

        function updateSequentialAccessText() {
            if ($sequentialAccessToggle.is(':checked')) {
                $sequentialAccessText.text('{{ __("Sequential (Step by step)") }}');
            } else {
                $sequentialAccessText.text('{{ __("Any Order (Free access)") }}');
            }
        }

        $sequentialAccessToggle.on('change', updateSequentialAccessText);

        // Certificate Toggle (Only for Free Courses)
        const $certificateToggle = $('#certificate_enabled');
        const $certificateText = $('.certificate-enabled-text');
        const $certificateToggleField = $('.certificate-toggle-field');
        const $certificateFeeField = $('.certificate-fee-field');
        const $certificateFeeInput = $('#certificate_fee');

        function updateCertificateToggle() {
            if ($certificateToggle.is(':checked')) {
                $certificateText.text('{{ __("Yes") }}');
                $certificateFeeField.show();
                $certificateFeeInput.attr('required', 'required');
            } else {
                $certificateText.text('{{ __("No") }}');
                $certificateFeeField.hide();
                $certificateFeeInput.removeAttr('required').val('');
            }
        }

        function toggleCertificateFields() {
            if ($freeRadio.is(':checked')) {
                $certificateToggleField.show();
            } else {
                $certificateToggleField.hide();
                $certificateFeeField.hide();
                $certificateToggle.prop('checked', false);
                $certificateFeeInput.removeAttr('required').val('');
            }
        }

        $certificateToggle.on('change', updateCertificateToggle);
        $freeRadio.on('change', toggleCertificateFields);
        $paidRadio.on('change', toggleCertificateFields);

        // Initialize tooltips
        $('[data-toggle="tooltip"]').tooltip();

        // Ensure disabled fields are not sent and Parsley ignores them
        $form.on('submit', function (event) {
            if ($freeRadio.is(':checked')) {
                $priceInput.prop('disabled', true);
                $discountPriceInput.prop('disabled', true);
                $priceInput.removeAttr('required').removeAttr('data-parsley-required');
            }
        });

        // Intro Video Type Toggle
        $('input[name="intro_video_type"]').on('change', function () {
            const type = $(this).val();
            $('#intro_video_file_wrapper').toggle(type === 'file');
            $('#intro_video_url_wrapper').toggle(type === 'url');
            $('#intro_video_error').hide();
            if (type !== 'file') $('#intro_video').val('');
            if (type !== 'url') $('#intro_video_url').val('');
        });

        // Validate file size on file selection
        $('#intro_video').on('change', function () {
            const maxVideoSizeMB = parseFloat('{{ $maxVideoSizeMB ?? 100 }}');
            const maxVideoSizeBytes = maxVideoSizeMB * 1024 * 1024;
            const file = this.files[0];
            if (file && file.size > maxVideoSizeBytes) {
                const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                $('#intro_video_error_text').text('{{ __("File too large:") }} ' + sizeMB + ' MB. {{ __("Max:") }} ' + maxVideoSizeMB + ' MB');
                $('#intro_video_error').show();
                $(this).addClass('is-invalid');
            } else {
                $('#intro_video_error').hide();
                $(this).removeClass('is-invalid');
            }
        });
    });

    // Pre-submit validation (intro video: exactly one of file or URL when type chosen)
    function validateVideoFileSize() {
        const selectedType = $('input[name="intro_video_type"]:checked').val();
        const maxVideoSizeMB = parseFloat('{{ $maxVideoSizeMB ?? 100 }}');
        const maxVideoSizeBytes = maxVideoSizeMB * 1024 * 1024;
        if (selectedType === 'file') {
            const file = $('#intro_video').length && $('#intro_video')[0].files[0];
            if (!file) {
                $('#intro_video_error_text').text('{{ __("Please select a video file to upload.") }}');
                $('#intro_video_error').show();
                $('html, body').animate({ scrollTop: $('#intro_video').offset().top - 150 }, 500);
                return false;
            }
            if (file.size > maxVideoSizeBytes) {
                const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                $('#intro_video_error_text').text('{{ __("File too large:") }} ' + sizeMB + ' MB. {{ __("Max:") }} ' + maxVideoSizeMB + ' MB');
                $('#intro_video_error').show();
                $('html, body').animate({ scrollTop: $('#intro_video').offset().top - 150 }, 500);
                return false;
            }
        } else if (selectedType === 'url') {
            const url = $('#intro_video_url').val().trim();
            if (!url) {
                $('#intro_video_error_text').text('{{ __("Please enter a video URL.") }}');
                $('#intro_video_error').show();
                $('html, body').animate({ scrollTop: $('#intro_video_url').offset().top - 150 }, 500);
                return false;
            }
        }
        $('#intro_video_error').hide();
        return true;
    }

    // Learning repeater functions
    let learningIndex = 1;
    function addLearning() {
        const container = document.getElementById('course-learnings-repeater');
        const newInput = document.createElement('div');
        newInput.className = 'input-group mb-2';
        newInput.innerHTML = `
                <input type="text" name="learnings_data[${learningIndex}][learning]" class="form-control" placeholder="{{ __('Enter learning objective') }}">
                <div class="input-group-append">
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeLearning(this)">-</button>
                </div>
            `;
        container.appendChild(newInput);
        learningIndex++;
    }

    function removeLearning(button) {
        button.parentElement.parentElement.remove();
    }

    // Requirements repeater functions
    let requirementIndex = 1;
    function addRequirement() {
        const container = document.getElementById('course-requirements-repeater');
        const newInput = document.createElement('div');
        newInput.className = 'input-group mb-2';
        newInput.innerHTML = `
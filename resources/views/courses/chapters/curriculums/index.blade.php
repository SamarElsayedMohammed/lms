@extends('layouts.app')

@section('title')
    {{ __('Manage Course Chapter Curriculum') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
    </div>
@endsection
@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('Create Curriculum') }}
                        </h4>
                        {{-- Form Start --}}
                        <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('course-chapters.curriculum.store', $chapter->id) }}" data-parsley-validate enctype="multipart/form-data" data-success-function="formSuccessFunction">
                            <div class="row">

                                {{-- Courses --}}
                                <div class="form-group mandatory col-sm-12 col-md-6 col-xl-4 ">
                                    <label for="course_id" class="form-label">{{ __('Course') }}</label>
                                    <input type="text" id="course_id" class="form-control" value="{{ $chapter->course->title }}" disabled>

                                </div>

                                {{-- Chapters --}}
                                <div class="form-group mandatory col-sm-12 col-md-6 col-xl-4 ">
                                    <label for="chapter_id" class="form-label">{{ __('Chapter') }}</label>
                                    <input type="text" id="chapter_id" class="form-control" value="{{ $chapter->title }}" disabled>
                                </div>

                                {{-- Status is always active by default --}}
                                <input type="hidden" name="is_active" value="1">

                                <div><hr></div>
                                {{-- Type --}}
                                <div class="form-group mandatory col-12">
                                    <label for="course-chapter-type" class="form-label">{{ __('Type') }}</label>
                                    <select name="type" id="course-chapter-type" class="form-control" required data-parsley-required="true" data-parsley-required-message="{{ __('The curriculum type is required.') }}">
                                        <option value="">{{ __('Select Type') }}</option>
                                        <option value="lecture">{{ __('Lecture') }}</option>
                                        <option value="document">{{ __('Document') }}</option>
                                        <option value="quiz">{{ __('Quiz') }}</option>
                                        <option value="assignment">{{ __('Assignment') }}</option>
                                    </select>
                                </div>

                                {{-- Lecture Container --}}
                                <div class="lecture-container row" style="display: none;">
                                    {{-- Lecture Title --}}
                                    <div class="form-group mandatory col-12">
                                        <label class="form-label d-block" for="lecture-title">{{ __('Lecture Title') }} </label>
                                        <input type="text" name="lecture_title" id="lecture-title" class="form-control" placeholder="{{ __('Video Title') }}">
                                    </div>

                                    {{-- Lecture Description --}}
                                    <div class="form-group col-12">
                                        <label class="form-label d-block" for="lecture-description">{{ __('Lecture Description') }} </label>
                                        <textarea name="lecture_description" id="lecture-description" class="form-control" placeholder="{{ __('Lecture Description') }}"></textarea>
                                    </div>

                                    {{-- Lecture Type --}}
                                    <div class="form-group mandatory col-sm-12 col-md-6 col-xl-4">
                                        <label class="form-label d-block" for="lecture_type">{{ __('Lecture Type') }} </label>
                                        {{-- URL --}}
                                        {{-- <div class="custom-control custom-radio custom-control-inline">
                                            <input type="radio" id="lecture-type-url" name="lecture_type" value="url" class="custom-control-input lecture-type lecture-type-url" required checked>
                                            <label class="custom-control-label" for="lecture-type-url">{{ __('URL') }}</label>
                                        </div> --}}
                                        {{-- File --}}
                                        <div class="custom-control custom-radio custom-control-inline">
                                            <input type="radio" id="lecture-type-file" name="lecture_type" value="file" class="custom-control-input lecture-type lecture-type-file" required checked>
                                            <label class="custom-control-label" for="lecture-type-file">{{ __('File') }}</label>
                                        </div>
                                        {{-- Youtube URL --}}
                                        <div class="custom-control custom-radio custom-control-inline">
                                            <input type="radio" id="lecture-type-youtube-url" name="lecture_type" value="youtube_url" class="custom-control-input lecture-type lecture-type-youtube-url" required>
                                            <label class="custom-control-label" for="lecture-type-youtube-url">{{ __('Youtube URL') }}</label>
                                        </div>
                                    </div>



                                    {{-- Lecture File Input --}}
                                    <div class="form-group lecture-file col-sm-12 col-md-6 col-xl-4 mandatory">
                                        <label class="form-label d-block" for="lecture_file">{{ __('Lecture File') }} </label>
                                        <input type="file" name="lecture_file" id="lecture_file" class="form-control lecture-file-input" placeholder="{{ __('Lecture File') }}" data-parsley-required-message="{{ __('Lecture File is required when Lecture Type is File') }}" accept="video/*,application/pdf,text/plain,text/markdown,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation">
                                    </div>

                                    {{-- Lecture Youtube URL Input --}}
                                    <div class="form-group mandatory lecture-youtube-url col-sm-12 col-md-6 col-xl-4">
                                        <label class="form-label d-block" for="lecture_youtube_url">{{ __('Lecture Youtube URL') }} </label>
                                        <input type="text" name="lecture_youtube_url" class="form-control lecture-youtube-url-input" placeholder="{{ __('Lecture Youtube URL') }}">
                                    </div>

                                    <div class="row">
                                        {{-- Lecture Hours --}}
                                        <div class="form-group mandatory col-sm-12 col-md-6 col-xl-4">
                                            <label class="form-label d-block" for="lecture-hours">{{ __('Lecture Hours') }} </label>
                                            <input type="number" name="lecture_hours" id="lecture-hours" class="form-control" placeholder="{{ __('LectureHours') }}" min="0">
                                        </div>

                                        {{-- Lecture Minutes --}}
                                        <div class="form-group mandatory col-sm-12 col-md-6 col-xl-4">
                                            <label class="form-label d-block" for="lecture-minutes">{{ __('Lecture Minutes') }} </label>
                                            <input type="number" name="lecture_minutes" id="lecture-minutes" class="form-control" placeholder="{{ __('Lecture Minutes') }}" min="0" max="59">
                                        </div>
                                        {{-- Lecture Seconds --}}
                                        <div class="form-group mandatory col-sm-12 col-md-6 col-xl-4">
                                            <label class="form-label d-block" for="lecture-seconds">{{ __('Lecture Seconds') }} </label>
                                            <input type="number" name="lecture_seconds" id="lecture-seconds" class="form-control" placeholder="{{ __('Lecture Seconds') }}" min="0" max="59">
                                        </div>
                                    </div>

                                    {{-- Free Preview --}}
                                    <div class="form-group col-sm-12 col-md-6 col-xl-3">
                                        <div class="control-label">{{ __('Free Preview') }}</div>
                                        <div class="custom-switches-stacked mt-2">
                                            <label class="custom-switch">
                                                <input type="checkbox" class="custom-switch-input custom-toggle-switch">
                                                <input type="hidden" name="lecture_free_preview" class="custom-toggle-switch-value" value="0">
                                                <span class="custom-switch-indicator"></span>
                                                <span class="custom-switch-description">{{ __('Yes') }}</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                {{-- Resource Toggle Section --}}
                                <div class="form-group col-12 resource-toggle-section">
                                    <div class="control-label">{{ __('Resource') }}</div>
                                    <div class="custom-switches-stacked mt-2">
                                        <label class="custom-switch">
                                            <input type="checkbox" class="custom-switch-input custom-toggle-switch" id="resource-toggle">
                                            <input type="hidden" name="resource_status" class="custom-toggle-switch-value" value="0">
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description">{{ __('Yes') }}</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="resource-container" style="display: none;">
                                    {{-- Resource Repeater Section --}}
                                    <div class="resource-section">
                                        <div data-repeater-list="resource_data">
                                            <div class="row resource-input-section d-flex align-items-center mb-2 bg-light p-3 rounded mt-2" data-repeater-item>
                                                <input type="hidden" name="id" class="id">
                                                {{-- Remove Resource --}}
                                                <div class="form-group col-12">
                                                    <button data-repeater-delete type="button" class="btn btn-danger remove-resource" title="{{ __('remove') }}">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                                {{-- Resource Type --}}
                                                <div class="form-group mandatory col-sm-12 col-lg-6">
                                                    <label class="form-label d-block">{{ __('Resource Type') }} </label>
                                                    <select name="resource_type" class="form-control course-chapter-resource-type" data-parsley-id="43">
                                                        <option value="">Select Resource Type</option>
                                                        <option value="url">URL</option>
                                                        <option value="file">File</option>
                                                    </select>
                                                </div>

                                                {{-- Resource Title --}}
                                                <div class="form-group mandatory col-sm-12 col-lg-6 resource-title-field">
                                                    <label class="form-label d-block">{{ __('Resource Title') }} </label>
                                                    <input type="text" name="resource_title" class="form-control resource-title-input" placeholder="{{ __('Resource Title') }}">
                                                </div>

                                                {{-- Resource URL Input --}}
                                                <div class="form-group mandatory resource-url col-sm-12 col-lg-6" style="display: none;">
                                                    <label class="form-label d-block">{{ __('Resource URL') }} </label>
                                                    <input type="text" name="resource_url" class="form-control resource-url-input" placeholder="{{ __('Resource URL') }}">
                                                </div>

                                                {{-- Resource File Input --}}
                                                <div class="form-group mandatory resource-file col-sm-12 col-lg-6" style="display: none;">
                                                    <label class="form-label d-block">{{ __('Resource File') }} </label>
                                                    <input type="file" name="resource_file" class="form-control resource-file-input" placeholder="{{ __('Resource File') }}" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.ods,.odp,.jpg,.jpeg,.png,.gif,.bmp,.tiff,.tif,.svg,.webp,.ico,.psd,.ai,.eps,.mp4,.mov,.avi,.wmv,.flv,.mkv,.webm,.m4v,.3gp,.3g2,.asf,.rm,.rmvb,.vob,.ogv,.mts,.m2ts,.mp3,.wav,.ogg,.m4a,.m4b,.m4p,.aac,.flac,.wma,.aiff,.au,.ra,.amr,.opus,.zip,.rar,.7z,.tar,.gz,.bz2,.xz">
                                                </div>
                                            </div>
                                        </div>
                                        {{-- Add New Resource --}}
                                        <button type="button" class="btn btn-success mt-1" data-repeater-create title="{{ __('Add New Resource') }}">
                                            <i class="fa fa-plus"></i> {{ __('Add New Resource') }}
                                        </button>
                                    </div>
                                    <div><hr></div>
                                </div>
                                {{-- End Resource Container --}}
                            </div>
                            {{-- End Lecture Container --}}

                                {{-- Document Container --}}
                                <div class="document-container row" style="display: none;">
                                    {{-- Document Type --}}
                                    <!--
                                    <div class="form-group mandatory col-sm-12 col-md-6">
                                        <label class="form-label d-block" for="document_type">{{ __('Document Type') }} </label>
                                        {{-- URL --}}
                                        <div class="custom-control custom-radio custom-control-inline">
                                            <input type="radio" id="document-type-url" name="document_type" value="url" class="custom-control-input document-type document-type-url" required checked>
                                            <label class="custom-control-label" for="document-type-url">{{ __('URL') }}</label>
                                        </div>
                                        {{-- File --}}
                                        <div class="custom-control custom-radio custom-control-inline">
                                            <input type="radio" id="document-type-file" name="document_type" value="file" class="custom-control-input document-type document-type-file" required>
                                            <label class="custom-control-label" for="document-type-file">{{ __('File') }}</label>
                                        </div>
                                    </div>
                                    -->
                                    <input type="hidden" name="document_type" value="file">

                                    {{-- Document URL Input --}}
                                    {{-- <div class="form-group mandatory document-url col-sm-12 col-md-6">
                                        <label class="form-label d-block" for="document_url">{{ __('Document URL') }} </label>
                                        <input type="text" name="document_url" class="form-control document-url-input" placeholder="{{ __('Document URL') }}">
                                    </div> --}}

                                    {{-- Document Title --}}
                                    <div class="form-group mandatory col-12">
                                        <label class="form-label d-block" for="document-title">{{ __('Document Title') }} </label>
                                        <input type="text" name="document_title" id="document-title" class="form-control" placeholder="{{ __('Document Title') }}">
                                    </div>

                                    {{-- Document Description --}}
                                    <div class="form-group col-12">
                                        <label class="form-label d-block" for="document-description">{{ __('Document Description') }} </label>
                                        <textarea name="document_description" id="document-description" class="form-control" placeholder="{{ __('Document Description') }}"></textarea>
                                    </div>

                                    {{-- Document File Input --}}
                                    <div class="form-group mandatory document-file col-sm-12 col-md-6">
                                        <label class="form-label d-block" for="document_file">{{ __('Document File') }}</label>
                                        <input type="file" name="document_file" id="document_file" class="form-control document-file-input" placeholder="{{ __('Document File') }}" required data-parsley-required="true" data-parsley-required-message="{{ __('Document File is required when type is Document') }}" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.ods,.odp,.md,.zip,.rar,.7z,.tar,.gz,.bz2,.xz,.jpg,.jpeg,.png,.gif,.bmp,.tiff,.tif,.svg,.webp,.ico,.psd,.ai,.eps,.mp4,.mov,.avi,.wmv,.flv,.mkv,.webm,.m4v,.3gp,.3g2,.asf,.rm,.rmvb,.vob,.ogv,.mts,.m2ts,.mp3,.wav,.ogg,.m4a,.m4b,.m4p,.aac,.flac,.wma,.aiff,.au,.ra,.amr,.opus">
                                        <small class="form-text text-muted">{{ __('Upload document, video, audio, or image files') }}</small>
                                    </div>

                                    {{-- Duration --}}
                                    <div class="form-group col-sm-12 col-md-6">
                                        <label class="form-label d-block" for="duration">{{ __('Duration (in seconds)') }} </label>
                                        <input type="number" name="duration" id="duration" class="form-control" placeholder="{{ __('Duration (in seconds)') }}" min="0">
                                        <small class="text-muted">{{ __('Estimated time for this resource in seconds') }}</small>
                                    </div>
                                </div>

                                {{-- Quiz Container --}}
                                <div class="quiz-container row" style="display: none;">
                                    {{-- Title --}}
                                    <div class="form-group mandatory col-12">
                                        <label class="form-label d-block" for="quiz-title">{{ __('Title') }} </label>
                                        <input type="text" name="quiz_title" id="quiz-title" class="form-control" placeholder="{{ __('Title') }}">
                                    </div>

                                    {{-- Description --}}
                                    <div class="form-group col-12">
                                        <label class="form-label d-block" for="quiz-description">{{ __('Description') }} </label>
                                        <textarea name="quiz_description" id="quiz-description" class="form-control" placeholder="{{ __('Description') }}"></textarea>
                                    </div>

                                    {{-- Time Limit --}}
                                    <div class="form-group col-12 col-xl-4">
                                        <label class="form-label d-block" for="quiz-time-limit">{{ __('Time Limit (in seconds)') }} </label>
                                        <input type="number" name="quiz_time_limit" id="quiz-time-limit" class="form-control" placeholder="{{ __('Time Limit') }}" min="0">
                                    </div>

                                    {{-- Total Points --}}
                                    <div class="form-group col-12 col-xl-4">
                                        <label class="form-label d-block" for="quiz-total-points">{{ __('Total Points') }} </label>
                                        <input type="number" name="quiz_total_points" id="quiz-total-points" class="form-control" placeholder="{{ __('Total Points') }}" min="0">
                                    </div>

                                    {{-- Passing Score --}}
                                    <div class="form-group col-12 col-xl-4">
                                        <label class="form-label d-block" for="quiz-passing-score">{{ __('Passing Score') }} </label>
                                        <input type="number" name="quiz_passing_score" id="quiz-passing-score" class="form-control" placeholder="{{ __('Passing Score (%)') }}" min="0" max="100">
                                    </div>

                                    {{-- Can Skip --}}
                                    <div class="form-group col-sm-12 col-lg-2">
                                        <label class="control-label">{{ __('Can Skip ?') }}</label>
                                        <div class="custom-switches-stacked mt-2">
                                            <label class="custom-switch">
                                                <input type="checkbox" class="custom-switch-input custom-toggle-switch can-skip-switch">
                                                <input type="hidden" name="quiz_can_skip" class="custom-toggle-switch-value quiz-can-skip" value="0">
                                                <span class="custom-switch-indicator"></span>
                                            </label>
                                        </div>
                                    </div>


                                    {{-- Quiz Questions Repeater Section --}}
                                    <div class="quiz-questions-section">
                                        <div data-repeater-list="quiz_data">
                                            <div class="row quiz-question-input-section d-flex align-items-center mb-2 bg-light p-2 rounded mt-2" data-repeater-item>
                                                {{-- Remove Question --}}
                                                <div class="form-group col-sm-12 col-lg-2 mt-4">
                                                    <button data-repeater-delete type="button" class="btn btn-danger remove-question" title="{{ __('remove') }}" disabled>
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                                <input type="hidden" name="question_id" class="question-id">
                                                {{-- Question Input --}}
                                                <div class="form-group mandatory question col-12">
                                                    <label class="form-label d-block" for="question">{{ __('Question') }} </label>
                                                    <textarea name="question" id="question" class="form-control question-input" placeholder="{{ __('Question') }}"></textarea>
                                                </div>

                                                {{-- Quiz Options Repeater Section --}}
                                                <div class="quiz-options-section col-12">
                                                    <div data-repeater-list="option_data">
                                                        <div class="row quiz-option-input-section" data-repeater-item>
                                                            {{-- Option 1 Input --}}
                                                            <div class="form-group mandatory option-1 col-sm-12 col-lg-6">
                                                                <label class="form-label d-block option-label" for="option-1">{{ __('Option 1') }} </label>
                                                                <input type="text" name="option" class="form-control option-input" placeholder="{{ __('Option 1') }}">
                                                            </div>
                                                            <input type="hidden" name="option_id" class="option-id">

                                                            {{-- Is Answer --}}
                                                            <div class="form-group col-sm-12 col-lg-2">
                                                                <label class="control-label">{{ __('Is Answer') }}</label>
                                                                <div class="custom-switches-stacked mt-2">
                                                                    <label class="custom-switch">
                                                                        <input type="checkbox" class="custom-switch-input custom-toggle-switch answer-switch">
                                                                        <input type="hidden" name="is_correct" class="custom-toggle-switch-value is-answer" value="0">
                                                                        <span class="custom-switch-indicator"></span>
                                                                    </label>
                                                                </div>
                                                            </div>

                                                            {{-- Remove Option 1 --}}
                                                            <div class="form-group col-sm-12 col-lg-2 mt-4">
                                                                <button data-repeater-delete type="button" class="btn btn-danger remove-option-1" title="{{ __('remove') }}">
                                                                    <i class="fa fa-times"></i>
                                                                </button>
                                                            </div>
                                                        </div>

                                                        <div class="row quiz-option-input-section" data-repeater-item>
                                                            {{-- Option 2 Input --}}
                                                            <div class="form-group mandatory option-2 col-sm-12 col-lg-6">
                                                                <label class="form-label d-block option-label" for="option-2">{{ __('Option 2') }} </label>
                                                                <input type="text" name="option" class="form-control option-input" placeholder="{{ __('Option 2') }}">
                                                            </div>
                                                            <input type="hidden" name="option_id" class="option-id">

                                                            {{-- Is Answer --}}
                                                            <div class="form-group col-sm-12 col-lg-2">
                                                                <label class="control-label">{{ __('Is Answer') }}</label>
                                                                <div class="custom-switches-stacked mt-2">
                                                                    <label class="custom-switch">
                                                                        <input type="checkbox" class="custom-switch-input custom-toggle-switch answer-switch">
                                                                        <input type="hidden" name="is_correct" class="custom-toggle-switch-value is-answer" value="0">
                                                                        <span class="custom-switch-indicator"></span>
                                                                    </label>
                                                                </div>
                                                            </div>

                                                            {{-- Remove Option 2 --}}
                                                            <div class="form-group col-sm-12 col-lg-2 mt-4">
                                                                <button data-repeater-delete type="button" class="btn btn-danger remove-option-2" title="{{ __('remove') }}">
                                                                    <i class="fa fa-times"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    {{-- Add New Option --}}
                                                    <button type="button" class="btn btn-primary mt-3 add-new-option" data-repeater-create title="{{ __('Add New Option') }}" style="width: 100%; padding: 10px 15px; font-weight: 500; border-radius: 6px;">
                                                        <i class="fa fa-plus mr-2"></i> {{ __('Add New Option') }}
                                                    </button>
                                                </div>

                                            </div>
                                        </div>
                                        {{-- Add New Question --}}
                                        <button type="button" class="btn btn-success mt-1" data-repeater-create title="{{ __('Add New Question') }}">
                                            <i class="fa fa-plus"></i> {{ __('Add New Question') }}
                                        </button>
                                    </div>
                                </div>
                                {{-- End Quiz Container --}}

                                {{-- Assignment Container --}}
                                <div class="assignment-container row" style="display: none;">
                                    {{-- Assignment Title --}}
                                    <div class="form-group mandatory col-sm-12 col-md-6">
                                        <label class="form-label d-block" for="assignment_title">{{ __('Assignment Title') }} </label>
                                        <input type="text" name="assignment_title" class="form-control assignment-title-input" placeholder="{{ __('Assignment Title') }}">
                                    </div>

                                    {{-- Points --}}
                                    <div class="form-group mandatory col-sm-12 col-md-6">
                                        <label class="form-label d-block" for="assignment-points">{{ __('Points') }} </label>
                                        <input type="number" name="assignment_points" id="assignment-points" class="form-control assignment-points-input" placeholder="{{ __('Points') }}">
                                    </div>

                                    {{-- Assignment Description --}}
                                    <div class="form-group mandatory col-12">
                                        <label class="form-label d-block" for="assignment-description">{{ __('Assignment Description') }} </label>
                                        <textarea name="assignment_description" id="assignment-description" class="form-control assignment-description-input" placeholder="{{ __('Assignment Description') }}"></textarea>
                                    </div>

                                    {{-- Assignment Instructions --}}
                                    <div class="form-group mandatory col-12">
                                        <label class="form-label d-block" for="assignment-instructions">{{ __('Assignment Instructions') }} </label>
                                        <textarea name="assignment_instructions" id="assignment-instructions" class="form-control assignment-instructions-input" placeholder="{{ __('Assignment Instructions') }}"></textarea>
                                    </div>

                                    {{-- Assignment Media --}}
                                    <div class="form-group col-12">
                                        <label class="form-label d-block" for="assignment-media">{{ __('Assignment Media') }} </label>
                                        <input type="file" name="assignment_media" id="assignment-media" class="form-control assignment-media-input" placeholder="{{ __('Assignment Media') }}" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.ods,.odp,.md,.zip,.rar,.7z,.tar,.gz,.bz2,.xz,.jpg,.jpeg,.png,.gif,.bmp,.tiff,.tif,.svg,.webp,.ico,.psd,.ai,.eps">
                                        <small class="form-text text-muted">{{ __('Upload document or image files for this assignment') }}</small>
                                    </div>

                                    {{-- Allowed File Types --}}
                                    <div class="form-group mandatory col-sm-12 col-md-6 col-lg-4">
                                        <label for="allowed-file-types" class="form-label">{{ __('Allowed File Types') }}</label>
                                        <select name="assignment_allowed_file_types[]" class="form-control tags-without-new-tag" multiple="multiple">
                                            <option value="audio">{{ __('Audio') }}</option>
                                            <option value="video">{{ __('Video') }}</option>
                                            <option value="document">{{ __('Document') }}</option>
                                            <option value="image">{{ __('Image') }}</option>
                                        </select>
                                    </div>

                                    {{-- Can Skip --}}
                                    <div class="form-group col-sm-12 col-lg-2">
                                        <label class="control-label">{{ __('Can Skip ?') }}</label>
                                        <div class="custom-switches-stacked mt-2">
                                            <label class="custom-switch">
                                                <input type="checkbox" class="custom-switch-input custom-toggle-switch can-skip-switch">
                                                <input type="hidden" name="assignment_can_skip" class="custom-toggle-switch-value assignment-can-skip" value="0">
                                                <span class="custom-switch-indicator"></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>



                            <input class="btn btn-primary float-right ml-3" type="submit" value="Submit">

                        </form>
                        {{-- Form End --}}
                    </div>
                </div>
            </div>
        </div>
        <!-- Table List -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        {{-- Table Title --}}
                        <h4 class="card-title">
                            {{ __('List Curriculum Items') }}
                        </h4>
                        {{-- Table Start --}}

                        <table aria-describedby="mydesc" class="table reorder-table-row" id="table_list" data-toggle="table" data-url="{{ route('course-chapters.curriculum.list', $chapter->id) }}" data-click-to-select="true" data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true" data-trim-on-search="false" data-mobile-responsive="true" data-use-row-attr-func="true" data-maintain-selected="true" data-export-data-type="all" data-export-options='{ "fileName": "{{ __('course-chapters') }}-<?=
    date('d-m-y')
?>" ,"ignoreColumn":["operate"]}' data-show-export="true" data-query-params="queryParams" data-table="course_chapter" data-status-column="is_active" data-reorderable-rows="true">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false" data-escape="true"> {{ __('ID') }}</th>
                                    <th scope="col" data-field="no" data-align="center" data-escape="true">{{ __('No.') }}</th>
                                    <th scope="col" data-field="title" data-align="center" data-escape="true">{{ __('Title') }}</th>
                                    <th scope="col" data-field="type" data-align="center" data-formatter="capitalizeNameFormatter" data-escape="false">{{ __('Type') }}</th>
                                    <th scope="col" data-field="level" data-align="center" data-formatter="capitalizeNameFormatter" data-escape="false">{{ __('Level') }}</th>
                                    <th scope="col" data-field="course_type" data-align="center" data-formatter="capitalizeNameFormatter" data-escape="false">{{ __('Course Type') }}</th>
                                    <th scope="col" data-field="instructor" data-align="center" data-escape="true">{{ __('Instructor') }}</th>
                                    <th scope="col" data-field="view_details" data-formatter="viewDetailsFormatter" data-align="center" data-escape="false">{{ __('View Details') }}</th>
                                    <th scope="col" data-field="duration" data-align="center" data-escape="true">{{ __('Duration') }}</th>
                                    <th scope="col" data-field="status_text" data-align="center" data-formatter="activeInactiveStatusFormatter" data-escape="false">{{ __('Status') }}</th>
                                    <th scope="col" data-field="resources" data-align="center" data-formatter="yesAndNoStatusFormatter" data-escape="false">{{ __('Resources ?') }}</th>
                                    <th scope="col" data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="courseChapterAction" data-escape="false">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>
                        {{-- Table End --}}

                        <span class="d-block mb-4 mt-2 text-danger small">{{ __('Note :- you can change the rank of rows by dragging rows') }}</span>
                        <div class="mt-1 d-md-block">
                            <button id="change-order-form-field" class="btn btn-primary">{{ __('update_rank') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- View Details Modal --}}
    <div class="modal fade" id="viewDetailsModal" tabindex="-1" role="dialog" aria-labelledby="viewDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewDetailsModalLabel">{{ __('Curriculum Details') }}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="curriculumDetailsBody">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="sr-only">{{ __('Loading...') }}</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Close') }}</button>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('script')
<script>
    function formSuccessFunction(response){
        setTimeout(() => {
            window.location.reload();
        }, 1500);
    }

    // Formatter for Active/Inactive status
    function activeInactiveStatusFormatter(value, row, index) {
        if (value === 'Active' || value === 'active' || row.is_active === true || row.status === true) {
            return '<span class="badge badge-success">' + (value || 'Active') + '</span>';
        } else {
            return '<span class="badge badge-danger">' + (value || 'Inactive') + '</span>';
        }
    }

    // Handle curriculum type change
    $(document).on('change', '#course-chapter-type', function() {
        var selectedType = $(this).val();
        var $typeField = $(this);

        // Clear any existing errors on type field
        $typeField.removeClass('parsley-error');
        $typeField.nextAll('.parsley-errors-list').remove();

        // Hide all containers
        $('.lecture-container, .document-container, .quiz-container, .assignment-container').hide();

        // Remove required attributes from all file inputs
        $('.document-file-input, .lecture-file-input, .quiz-file-input, .assignment-file-input').removeAttr('required').removeAttr('data-parsley-required');

        // Show the selected container
        if (selectedType) {
            $('.' + selectedType + '-container').show();

            // Add required attribute based on type
            if (selectedType === 'document') {
                $('#document_file').attr('required', 'required').attr('data-parsley-required', 'true');
            } else if (selectedType === 'lecture') {
                // Lecture file is conditionally required based on lecture_type
                // Check initial lecture type and set required accordingly
                var lectureType = $('input[name="lecture_type"]:checked').val();
                if (lectureType === 'file') {
                    $('#lecture_file').attr('required', 'required').attr('data-parsley-required', 'true');
                }
            }
        }
    });

    // Handle lecture type change to add/remove required attribute
    $(document).on('change', 'input[name="lecture_type"]', function() {
        var lectureType = $(this).val();
        if (lectureType === 'file') {
            $('#lecture_file').attr('required', 'required').attr('data-parsley-required', 'true');
        } else {
            $('#lecture_file').removeAttr('required').removeAttr('data-parsley-required');
        }
    });

    // Custom validation for form submission - run BEFORE Parsley
    // Use namespace to avoid conflict with default handler
    $('.create-form').off('submit').on('submit.curriculum', function(e) {
        // Prevent default form submission and Parsley validation
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation(); // Stop all other handlers

        var selectedType = $('#course-chapter-type').val();
        var isValid = true;
        var errorMessage = '';
        var $form = $(this);

        // First, clear ALL existing errors from lecture file and resource fields
        // Exclude from Parsley validation to prevent duplicate errors
        $('#lecture_file').each(function() {
            var $field = $(this);
            // Remove all error classes and messages
            $field.removeClass('parsley-error');
            $field.nextAll('.parsley-errors-list').remove();
            // Exclude from Parsley validation
            $field.attr('data-parsley-excluded', 'true');
        });

        $('[data-repeater-item]:visible').find('.course-chapter-resource-type, .resource-title-input, .resource-url-input, .resource-file-input').each(function() {
            var $field = $(this);
            // Remove all error classes and messages
            $field.removeClass('parsley-error');
            $field.nextAll('.parsley-errors-list').remove();
            // Exclude from Parsley validation
            $field.attr('data-parsley-excluded', 'true');
        });

        // Check if type is selected
        if (!selectedType || selectedType === '') {
            isValid = false;
            var $typeField = $('#course-chapter-type');
            // Remove ALL existing errors first
            $typeField.nextAll('.parsley-errors-list').remove();
            $typeField.removeClass('parsley-error');
            // Add single error message after the select field
            $typeField.after('<ul class="parsley-errors-list filled" style="margin-top: 5px;"><li class="parsley-required" style="color: #dc3545; font-size: 0.875rem;">{{ __("The curriculum type is required.") }}</li></ul>');
            $typeField.addClass('parsley-error');
        } else {
            // Remove error if type is selected
            $('#course-chapter-type').removeClass('parsley-error');
            $('#course-chapter-type').nextAll('.parsley-errors-list').remove();
        }

        // Check document file if type is document
        if (selectedType === 'document') {
            var documentFile = $('#document_file')[0];
            if (!documentFile || !documentFile.files || documentFile.files.length === 0) {
                isValid = false;
                errorMessage = '{{ __("Document File is required when type is Document") }}';
                var $documentFileField = $('#document_file');
                // Remove ALL existing errors first
                $documentFileField.nextAll('.parsley-errors-list').remove();
                $documentFileField.removeClass('parsley-error');
                // Add single error message after the input field
                $documentFileField.after('<ul class="parsley-errors-list filled" style="margin-top: 5px;"><li class="parsley-required" style="color: #dc3545; font-size: 0.875rem;">' + errorMessage + '</li></ul>');
                $documentFileField.addClass('parsley-error');
            } else {
                // Remove error if file is selected
                $('#document_file').removeClass('parsley-error');
                $('#document_file').nextAll('.parsley-errors-list').remove();
            }
        }

        // Check lecture file if type is lecture and lecture_type is file
        if (selectedType === 'lecture') {
            var lectureType = $('input[name="lecture_type"]:checked').val();
            if (lectureType === 'file') {
                var lectureFile = $('#lecture_file')[0];
                if (!lectureFile || !lectureFile.files || lectureFile.files.length === 0) {
                    isValid = false;
                    errorMessage = '{{ __("Lecture File is required when Lecture Type is File") }}';
                    var $lectureFileField = $('#lecture_file');
                    // Remove ALL existing errors first (already cleared above, but ensure)
                    $lectureFileField.nextAll('.parsley-errors-list').remove();
                    $lectureFileField.removeClass('parsley-error');
                    // Add single error message after the input field
                    $lectureFileField.after('<ul class="parsley-errors-list filled" style="margin-top: 5px;"><li class="parsley-required" style="color: #dc3545; font-size: 0.875rem;">' + errorMessage + '</li></ul>');
                    $lectureFileField.addClass('parsley-error');
                } else {
                    // Remove error if file is selected
                    $('#lecture_file').removeClass('parsley-error');
                    $('#lecture_file').nextAll('.parsley-errors-list').remove();
                }
            } else {
                // Remove error if lecture type is not file
                $('#lecture_file').removeClass('parsley-error');
                $('#lecture_file').nextAll('.parsley-errors-list').remove();
            }
        }

        // Check quiz questions if type is quiz
        if (selectedType === 'quiz') {
            // Clear all existing errors from quiz fields first
            $('.question-input, .option-input').each(function() {
                var $field = $(this);
                $field.removeClass('parsley-error');
                $field.nextAll('.parsley-errors-list').remove();
                $field.attr('data-parsley-excluded', 'true');
            });
            $('.quiz-error-message').remove();

            // Check all quiz questions (use quiz-question-input-section class to identify question rows)
            var hasQuestions = false;
            $('.quiz-question-input-section').each(function() {
                var $questionRow = $(this);
                var $questionInput = $questionRow.find('.question-input');
                hasQuestions = true;
                var questionText = $questionInput.val();

                // Validate question text
                if (!questionText || questionText.trim() === '') {
                    isValid = false;
                    // Remove existing errors
                    $questionInput.nextAll('.parsley-errors-list').remove();
                    $questionInput.removeClass('parsley-error');
                    // Add error message
                    $questionInput.after('<ul class="parsley-errors-list filled" style="margin-top: 5px;"><li class="parsley-required" style="color: #dc3545; font-size: 0.875rem;">{{ __("Question is required") }}</li></ul>');
                    $questionInput.addClass('parsley-error');
                } else {
                    $questionInput.removeClass('parsley-error');
                    $questionInput.nextAll('.parsley-errors-list').remove();
                }

                // Validate options for this question (find option rows within this question row)
                var $optionRows = $questionRow.find('.quiz-option-input-section');
                var hasOptions = false;
                var hasAnswer = false;

                $optionRows.each(function() {
                    var $optionRow = $(this);
                    var $optionInput = $optionRow.find('.option-input');
                    var optionText = $optionInput.val();
                    // Check if this option is marked as answer
                    var isAnswerChecked = $optionRow.find('.custom-switch-input').is(':checked');
                    var isAnswerValue = $optionRow.find('.custom-toggle-switch-value').val() == '1';
                    var isAnswer = isAnswerChecked || isAnswerValue;

                    if (optionText && optionText.trim() !== '') {
                        hasOptions = true;
                        if (isAnswer) {
                            hasAnswer = true;
                        }
                    }
                });

                // Check if question has at least one option
                if (!hasOptions) {
                    isValid = false;
                    var $firstOptionInput = $optionRows.first().find('.option-input');
                    if ($firstOptionInput.length > 0) {
                        $firstOptionInput.nextAll('.parsley-errors-list').remove();
                        $firstOptionInput.removeClass('parsley-error');
                        $firstOptionInput.after('<ul class="parsley-errors-list filled" style="margin-top: 5px;"><li class="parsley-required" style="color: #dc3545; font-size: 0.875rem;">{{ __("At least one option is required for each question") }}</li></ul>');
                        $firstOptionInput.addClass('parsley-error');
                    }
                }

                // Check if question has at least one answer
                if (hasOptions && !hasAnswer) {
                    isValid = false;
                    var $firstOptionInput = $optionRows.first().find('.option-input');
                    if ($firstOptionInput.length > 0) {
                        // Remove existing errors
                        $firstOptionInput.nextAll('.parsley-errors-list').remove();
                        $firstOptionInput.removeClass('parsley-error');
                        // Add error message
                        $firstOptionInput.after('<ul class="parsley-errors-list filled" style="margin-top: 5px;"><li class="parsley-required" style="color: #dc3545; font-size: 0.875rem;">{{ __("At least one answer must be selected for each question") }}</li></ul>');
                        $firstOptionInput.addClass('parsley-error');
                    }
                }
            });

            // Check if at least one question exists
            if (!hasQuestions) {
                isValid = false;
                errorMessage = '{{ __("At least one question is required for quiz") }}';
                // Show error at the top of quiz section
                var $quizSection = $('.quiz-questions-section');
                if ($quizSection.find('.quiz-error-message').length === 0) {
                    $quizSection.prepend('<div class="alert alert-danger quiz-error-message" style="margin-bottom: 15px;">' + errorMessage + '</div>');
                }
            } else {
                $('.quiz-error-message').remove();
            }
        }

        // Check resource fields if resource toggle is ON
        var resourceToggleOn = $('#resource-toggle').is(':checked');
        if (resourceToggleOn) {
            // Check all resource items (exclude template items that are not visible)
            $('.resource-section [data-repeater-item]:visible').each(function() {
                var $row = $(this);
                var resourceType = $row.find('.course-chapter-resource-type').val();
                var resourceTitle = $row.find('.resource-title-input').val();

                // Check resource type
                if (!resourceType || resourceType === '') {
                    isValid = false;
                    var resourceTypeSelect = $row.find('.course-chapter-resource-type');
                    // Remove ALL existing errors first
                    resourceTypeSelect.nextAll('.parsley-errors-list').remove();
                    resourceTypeSelect.removeClass('parsley-error');
                    // Add single error message after the select field
                    resourceTypeSelect.after('<ul class="parsley-errors-list filled" style="margin-top: 5px;"><li class="parsley-required" style="color: #dc3545; font-size: 0.875rem;">{{ __("Resource Type is required") }}</li></ul>');
                    resourceTypeSelect.addClass('parsley-error');
                } else {
                    $row.find('.course-chapter-resource-type').removeClass('parsley-error');
                    $row.find('.course-chapter-resource-type').nextAll('.parsley-errors-list').remove();
                }

                // Check resource title
                if (!resourceTitle || resourceTitle.trim() === '') {
                    isValid = false;
                    var resourceTitleInput = $row.find('.resource-title-input');
                    // Remove ALL existing errors first
                    resourceTitleInput.nextAll('.parsley-errors-list').remove();
                    resourceTitleInput.removeClass('parsley-error');
                    // Add single error message after the input field
                    resourceTitleInput.after('<ul class="parsley-errors-list filled" style="margin-top: 5px;"><li class="parsley-required" style="color: #dc3545; font-size: 0.875rem;">{{ __("Resource Title is required") }}</li></ul>');
                    resourceTitleInput.addClass('parsley-error');
                } else {
                    $row.find('.resource-title-input').removeClass('parsley-error');
                    $row.find('.resource-title-input').nextAll('.parsley-errors-list').remove();
                }

                // Check resource URL or File based on type
                if (resourceType === 'url') {
                    var resourceUrl = $row.find('.resource-url-input').val();
                    if (!resourceUrl || resourceUrl.trim() === '') {
                        isValid = false;
                        var resourceUrlInput = $row.find('.resource-url-input');
                        // Remove ALL existing errors first
                        resourceUrlInput.nextAll('.parsley-errors-list').remove();
                        resourceUrlInput.removeClass('parsley-error');
                        // Add single error message after the input field
                        resourceUrlInput.after('<ul class="parsley-errors-list filled" style="margin-top: 5px;"><li class="parsley-required" style="color: #dc3545; font-size: 0.875rem;">{{ __("Resource URL is required when Resource Type is URL") }}</li></ul>');
                        resourceUrlInput.addClass('parsley-error');
                    } else {
                        $row.find('.resource-url-input').removeClass('parsley-error');
                        $row.find('.resource-url-input').nextAll('.parsley-errors-list').remove();
                    }
                } else if (resourceType === 'file') {
                    var resourceFile = $row.find('.resource-file-input')[0];
                    if (!resourceFile || !resourceFile.files || resourceFile.files.length === 0) {
                        // Check if there's an existing file (for edit mode)
                        var existingFileUrl = $row.find('.resource-file-url').val();
                        if (!existingFileUrl) {
                            isValid = false;
                            var resourceFileInput = $row.find('.resource-file-input');
                            // Remove ALL existing errors first
                            resourceFileInput.nextAll('.parsley-errors-list').remove();
                            resourceFileInput.removeClass('parsley-error');
                            // Add single error message after the input field
                            resourceFileInput.after('<ul class="parsley-errors-list filled" style="margin-top: 5px;"><li class="parsley-required" style="color: #dc3545; font-size: 0.875rem;">{{ __("Resource File is required when Resource Type is File") }}</li></ul>');
                            resourceFileInput.addClass('parsley-error');
                        } else {
                            $row.find('.resource-file-input').removeClass('parsley-error');
                            $row.find('.resource-file-input').nextAll('.parsley-errors-list').remove();
                        }
                    } else {
                        $row.find('.resource-file-input').removeClass('parsley-error');
                        $row.find('.resource-file-input').nextAll('.parsley-errors-list').remove();
                    }
                }
            });
        }

        if (!isValid) {
            // Scroll to first error
            var $errorElement = $('.parsley-error').first();
            if ($errorElement.length > 0) {
                $('html, body').animate({
                    scrollTop: $errorElement.offset().top - 100
                }, 500);
            }
            return false;
        }

        // If validation passes, remove excluded attribute and submit form via AJAX without showing toast for validation errors
        $('#lecture_file').removeAttr('data-parsley-excluded');
        $('[data-repeater-item]').find('.course-chapter-resource-type, .resource-title-input, .resource-url-input, .resource-file-input').removeAttr('data-parsley-excluded');
        $('.question-input, .option-input').removeAttr('data-parsley-excluded');

        // Submit form via AJAX manually to prevent toast on validation errors
        var formData = new FormData($form[0]);
        var url = $form.attr('action');
        var submitButton = $form.find(':submit');
        var originalButtonText = submitButton.val();

        submitButton.attr('disabled', true);

        $.ajax({
            type: 'POST',
            url: url,
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                submitButton.attr('disabled', false).val(originalButtonText);
                if (response.success || !response.error) {
                    // Show success toast only
                    if (typeof showSuccessToast === 'function') {
                        showSuccessToast(response.message || '{{ __("Curriculum created successfully") }}');
                    }
                    // Call success function if exists
                    var successFunction = $form.data('success-function');
                    if (successFunction && typeof window[successFunction] === 'function') {
                        window[successFunction](response);
                    } else if (typeof window.formSuccessFunction === 'function') {
                        window.formSuccessFunction(response);
                    }
                } else {
                    // Show toast for non-validation errors (e.g. authorization)
                    if (response.message && !response.errors) {
                        if (typeof showErrorToast === 'function') {
                            showErrorToast(response.message);
                        }
                    }

                    // Don't show toast for validation errors, just show inline errors
                    if (response.errors) {
                        // Handle validation errors inline (already handled by our custom validation)
                        // Just scroll to first error
                        var $errorElement = $('.parsley-error').first();
                        if ($errorElement.length > 0) {
                            $('html, body').animate({
                                scrollTop: $errorElement.offset().top - 100
                            }, 500);
                        }
                    }
                }
            },
            error: function(jqXHR) {
                submitButton.attr('disabled', false).val(originalButtonText);
                // Never show toast for validation errors - display inline only
                if (jqXHR.responseJSON) {
                    if (jqXHR.responseJSON.errors || jqXHR.status === 422) {
                        // Validation errors - display inline below fields, don't show toast
                        var errors = jqXHR.responseJSON.errors || {};
                        var errorMessage = jqXHR.responseJSON.message || '';

                        // Display server-side validation errors inline
                        $.each(errors, function(field, messages) {
                            if (messages && messages.length > 0) {
                                // Find the corresponding field and show error below it
                                var fieldName = field.replace(/\./g, '_');
                                var $field = $('[name="' + field + '"], [name*="' + fieldName + '"]').first();

                                // For quiz_data fields, find the appropriate question/option field
                                if (field.indexOf('quiz_data') !== -1) {
                                    var match = field.match(/quiz_data\[(\d+)\]/);
                                    if (match) {
                                        var questionIndex = match[1];
                                        if (field.indexOf('question') !== -1) {
                                            $field = $('.quiz-question-input-section').eq(questionIndex).find('.question-input');
                                        } else if (field.indexOf('option_data') !== -1) {
                                            var optionMatch = field.match(/option_data\[(\d+)\]/);
                                            if (optionMatch) {
                                                var optionIndex = optionMatch[1];
                                                $field = $('.quiz-question-input-section').eq(questionIndex)
                                                    .find('.quiz-option-input-section').eq(optionIndex).find('.option-input');
                                            }
                                        }
                                    }
                                }

                                if ($field.length > 0) {
                                    // Remove existing errors
                                    $field.nextAll('.parsley-errors-list').remove();
                                    $field.removeClass('parsley-error');
                                    // Add error message below field
                                    $field.after('<ul class="parsley-errors-list filled" style="margin-top: 5px;"><li class="parsley-required" style="color: #dc3545; font-size: 0.875rem;">' + messages[0] + '</li></ul>');
                                    $field.addClass('parsley-error');
                                }
                            }
                        });

                        // Scroll to first error
                        if ($('.parsley-error').length > 0) {
                            $('html, body').animate({
                                scrollTop: $('.parsley-error').first().offset().top - 100
                            }, 500);
                        }
                    } else if (jqXHR.responseJSON.message && jqXHR.status !== 422) {
                        // Other errors (not validation) - show toast only if not 422 status
                        if (typeof showErrorToast === 'function') {
                            showErrorToast(jqXHR.responseJSON.message);
                        }
                    }
                } else if (jqXHR.status === 422) {
                    // 422 validation error without JSON response - don't show toast
                    if ($('.parsley-error').length > 0) {
                        $('html, body').animate({
                            scrollTop: $('.parsley-error').first().offset().top - 100
                        }, 500);
                    }
                }
            }
        });

        return false;
    });


    // Custom query params function to pass chapter_id
    function curriculumQueryParams(params) {
        params.chapter_id = {{ $chapter->id }};
        return params;
    }

    // Handle view details modal
    $(document).on('click', '.view-details-btn', function() {
        const curriculumId = $(this).data('id');
        const curriculumType = $(this).data('type');


        // Show loading state
        $('#curriculumDetailsBody').html(`
            <div class="text-center">
                <div class="spinner-border" role="status">
                    <span class="sr-only">{{ __('Loading...') }}</span>
                </div>
            </div>
        `);

        // Update modal title
        $('#viewDetailsModalLabel').text(`${curriculumType.charAt(0).toUpperCase() + curriculumType.slice(1)} {{ __('Details') }}`);
        let content = '';
        $.ajax({
            url: $(this).data('url'),
            type: 'GET',
            success: function(response) {
                // Fetch curriculum details
                // ResponseService returns { error: false/true, message: ..., data: ... }
                if (response.error === false && response.data) {
                    switch (curriculumType) {
                        case 'lecture':
                            content = buildLectureDetails(response.data);
                            break;
                        case 'quiz':
                            content = buildQuizDetails(response.data);
                            break;
                        case 'assignment':
                            content = buildAssignmentDetails(response.data);
                            break;
                        case 'resource':
                        case 'document':
                            content = buildResourceDetails(response.data);
                            break;
                        default:
                            content = '<div class="alert alert-warning">{{ __("Curriculum type not supported") }}</div>';
                            break;
                    }
                } else {
                    let errorMessage = response.message || '{{ __("Failed to load curriculum details") }}';
                    content = '<div class="alert alert-danger">' + errorMessage + '</div>';
                }
                $('#curriculumDetailsBody').html(content);
            },
            error: function(xhr, status, error) {
                let errorContent = '<div class="alert alert-danger">{{ __("Error loading curriculum details: ") }}' + error + '</div>';
                $('#curriculumDetailsBody').html(errorContent);
            }
        });

    });

    function buildLectureDetails(data) {
        let content = `
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-video mr-2"></i>{{ __('Lecture Information') }}</h5>
                    <table class="table table-borderless">
                        <tr><td><strong>{{ __('Title') }}:</strong></td><td>${data.title}</td></tr>
                        <tr><td><strong>{{ __('Type') }}:</strong></td><td>${data.type}</td></tr>
                        <tr><td><strong>{{ __('Duration') }}:</strong></td><td>${data.formatted_duration}</td></tr>
                        <tr><td><strong>{{ __('Free Preview') }}:</strong></td><td>${data.free_preview ? '{{ __("Yes") }}' : '{{ __("No") }}'}</td></tr>
                        <tr><td><strong>{{ __('Status') }}:</strong></td><td>${data.is_active ? '<span class="badge badge-success">{{ __("Active") }}</span>' : '<span class="badge badge-danger">{{ __("Inactive") }}</span>'}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h5><i class="fas fa-link mr-2"></i>{{ __('Content') }}</h5>
                    <div class="content-links">`;

        if (data.youtube_url) {
            content += `<p><strong>{{ __('YouTube URL') }}:</strong><br><a href="${data.youtube_url}" target="_blank" class="btn btn-sm btn-outline-danger"><i class="fab fa-youtube mr-1"></i>${data.youtube_url}</a></p>`;
        }

        if (data.file) {
            content += `<p><strong>{{ __('File') }}:</strong><br><a href="${data.file}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-file mr-1"></i>{{ __('View File') }}</a></p>`;
        }

        if (data.url) {
            content += `<p><strong>{{ __('URL') }}:</strong><br><a href="${data.url}" target="_blank" class="btn btn-sm btn-outline-info"><i class="fas fa-external-link-alt mr-1"></i>{{ __('Open Link') }}</a></p>`;
        }

        content += `</div></div></div>`;

        if (data.description) {
            content += `
                <div class="row mt-3">
                    <div class="col-12">
                        <h5><i class="fas fa-info-circle mr-2"></i>{{ __('Description') }}</h5>
                        <div class="card">
                            <div class="card-body">${data.description}</div>
                        </div>
                    </div>
                </div>`;
        }

        if (data.resources && data.resources.length > 0) {
            content += buildResourcesSection(data.resources);
        }

        return content;
    }

    function buildQuizDetails(data) {
        let content = `
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-question-circle mr-2"></i>{{ __('Quiz Information') }}</h5>
                    <table class="table table-borderless">
                        <tr><td><strong>{{ __('Title') }}:</strong></td><td>${data.title}</td></tr>
                        <tr><td><strong>{{ __('Time Limit') }}:</strong></td><td>${data.time_limit ? data.time_limit + ' {{ __("seconds") }}' : '{{ __("No limit") }}'}</td></tr>
                        <tr><td><strong>{{ __('Total Points') }}:</strong></td><td>${data.total_points || 0}</td></tr>
                        <tr><td><strong>{{ __('Passing Score') }}:</strong></td><td>${data.passing_score || 0}%</td></tr>
                        <tr><td><strong>{{ __('Can Skip') }}:</strong></td><td>${data.can_skip ? '{{ __("Yes") }}' : '{{ __("No") }}'}</td></tr>
                        <tr><td><strong>{{ __('Status') }}:</strong></td><td>${data.is_active ? '<span class="badge badge-success">{{ __("Active") }}</span>' : '<span class="badge badge-danger">{{ __("Inactive") }}</span>'}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h5><i class="fas fa-chart-bar mr-2"></i>{{ __('Quiz Statistics') }}</h5>
                    <table class="table table-borderless">
                        <tr><td><strong>{{ __('Total Questions') }}:</strong></td><td>${data.questions ? data.questions.length : 0}</td></tr>
                    </table>
                </div>
            </div>`;

        if (data.description) {
            content += `
                <div class="row mt-3">
                    <div class="col-12">
                        <h5><i class="fas fa-info-circle mr-2"></i>{{ __('Description') }}</h5>
                        <div class="card">
                            <div class="card-body">${data.description}</div>
                        </div>
                    </div>
                </div>`;
        }

        if (data.questions && data.questions.length > 0) {
            content += `
                <div class="row mt-3">
                    <div class="col-12">
                        <h5><i class="fas fa-list mr-2"></i>{{ __('Questions & Answers') }}</h5>`;

            data.questions.forEach((question, index) => {
                content += `
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between">
                            <div class="d-flex justify-content-between">
                                <strong>{{ __('Question') }} ${index + 1}:</strong> <span class="ml-2">${question.question}</span>
                            </div>
                            <div class="d-flex justify-content-end">
                                <strong>${question.points} {{ __('points') }}</strong>
                            </div>
                        </div>
                        <div class="card-body">`;

                if (question.options && question.options.length > 0) {
                    content += '<ul class="list-group list-group-flush">';
                    question.options.forEach((option, optIndex) => {
                        const isCorrect = option.is_correct;
                        const badgeClass = isCorrect ? 'badge-success' : 'badge-light';
                        const icon = isCorrect ? '<i class="fas fa-check"></i>' : '';
                        content += `<li class="list-group-item d-flex justify-content-between align-items-center">
                            ${option.option}
                            <span class="badge ${badgeClass}">${icon} ${isCorrect ? '{{ __("Correct") }}' : '{{ __("Option") }}'}</span>
                        </li>`;
                    });
                    content += '</ul>';
                }

                content += '</div></div>';
            });

            content += '</div></div>';
        }

        return content;
    }

    function buildAssignmentDetails(data) {
        let content = `
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-clipboard-list mr-2"></i>{{ __('Assignment Information') }}</h5>
                    <table class="table table-borderless">
                        <tr><td><strong>{{ __('Title') }}:</strong></td><td>${data.title}</td></tr>
                        <tr><td><strong>{{ __('Points') }}:</strong></td><td>${data.points || 0}</td></tr>
                        <tr><td><strong>{{ __('Can Skip') }}:</strong></td><td>${data.can_skip ? '{{ __("Yes") }}' : '{{ __("No") }}'}</td></tr>
                        <tr><td><strong>{{ __('Status') }}:</strong></td><td>${data.is_active ? '<span class="badge badge-success">{{ __("Active") }}</span>' : '<span class="badge badge-danger">{{ __("Inactive") }}</span>'}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h5><i class="fas fa-file-alt mr-2"></i>{{ __('File Requirements') }}</h5>`;

        if (data.allowed_file_types) {
            const fileTypes = Array.isArray(data.allowed_file_types) ? data.allowed_file_types : data.allowed_file_types.split(',');
            content += '<div class="mb-2"><strong>{{ __("Allowed File Types") }}:</strong></div>';
            content += '<div>';
            fileTypes.forEach(type => {
                content += `<span class="badge badge-info mr-1">${type.trim()}</span>`;
            });
            content += '</div>';
        } else {
            content += '<p class="text-muted">{{ __("No file type restrictions") }}</p>';
        }

        content += '</div></div>';

        if (data.description) {
            content += `
                <div class="row mt-3">
                    <div class="col-12">
                        <h5><i class="fas fa-info-circle mr-2"></i>{{ __('Description') }}</h5>
                        <div class="card">
                            <div class="card-body">${data.description}</div>
                        </div>
                    </div>
                </div>`;
        }

        if (data.instructions) {
            content += `
                <div class="row mt-3">
                    <div class="col-12">
                        <h5><i class="fas fa-tasks mr-2"></i>{{ __('Instructions') }}</h5>
                        <div class="card">
                            <div class="card-body">${data.instructions}</div>
                        </div>
                    </div>
                </div>`;
        }

        if (data.resources && data.resources.length > 0) {
            content += buildResourcesSection(data.resources);
        }

        return content;
    }

    function buildResourceDetails(data) {
        let content = `
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-file-alt mr-2"></i>{{ __('Document Information') }}</h5>
                    <table class="table table-borderless">
                        <tr><td><strong>{{ __('Title') }}:</strong></td><td>${data.title}</td></tr>
                        <tr><td><strong>{{ __('Type') }}:</strong></td><td>${data.type}</td></tr>
                        <tr><td><strong>{{ __('Duration') }}:</strong></td><td>${data.duration ? data.duration + ' {{ __("seconds") }}' : '{{ __("Not specified") }}'}</td></tr>
                        <tr><td><strong>{{ __('Status') }}:</strong></td><td>${data.is_active ? '<span class="badge badge-success">{{ __("Active") }}</span>' : '<span class="badge badge-danger">{{ __("Inactive") }}</span>'}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h5><i class="fas fa-link mr-2"></i>{{ __('Content') }}</h5>
                    <div class="content-links">`;

        if (data.file) {
            content += `<p><a href="${data.file}" target="_blank" class="btn btn-outline-primary"><i class="fas fa-file mr-1"></i>{{ __('View Document') }}</a></p>`;
        }

        if (data.url) {
            content += `<p><a href="${data.url}" target="_blank" class="btn btn-outline-info"><i class="fas fa-external-link-alt mr-1"></i>{{ __('Open Link') }}</a></p>`;
        }

        content += `</div></div></div>`;

        if (data.description) {
            content += `
                <div class="row mt-3">
                    <div class="col-12">
                        <h5><i class="fas fa-info-circle mr-2"></i>{{ __('Description') }}</h5>
                        <div class="card">
                            <div class="card-body">${data.description}</div>
                        </div>
                    </div>
                </div>`;
        }

        return content;
    }

    function buildResourcesSection(resources) {
        let content = `
            <div class="row mt-3">
                <div class="col-12">
                    <h5><i class="fas fa-paperclip mr-2"></i>{{ __('Resources') }}</h5>
                    <div class="list-group">`;

        resources.forEach(resource => {
            console.log(resource);
            const icon = resource.type === 'file' ? 'fas fa-file' : 'fas fa-link';
            const link = resource.type === 'file' ? resource.file : resource.url;
            const target = resource.type === 'file' ? '_blank' : '_blank';

            content += `
                <a href="${link}" target="${target}" class="list-group-item list-group-item-action">
                    <i class="${icon} mr-2"></i>
                    ${resource.type === 'file' ? '{{ __("Download Resource") }}' : '{{ __("Open Resource") }}'}
                </a>`;
        });

        content += '</div></div></div>';
        return content;
    }

    // Status toggle removed - all curriculum items are active by default

    // Handle resource toggle change
    $(document).on('change', '#resource-toggle', function() {
        var isChecked = $(this).is(':checked');
        if (isChecked) {
            // Add required attributes but NOT data-parsley-required to prevent Parsley validation
            // We'll handle validation manually in form submit
            $('.course-chapter-resource-type').attr('required', 'required');
            $('.resource-title-input').attr('required', 'required');
        } else {
            // Remove required attributes when toggle is OFF
            $('.course-chapter-resource-type').removeAttr('required').removeAttr('data-parsley-required');
            $('.resource-title-input').removeAttr('required').removeAttr('data-parsley-required');
            $('.resource-url-input').removeAttr('required').removeAttr('data-parsley-required');
            $('.resource-file-input').removeAttr('required').removeAttr('data-parsley-required');
            // Remove error classes and messages
            $('.course-chapter-resource-type, .resource-title-input, .resource-url-input, .resource-file-input').removeClass('parsley-error');
            $('[data-repeater-item]').find('.parsley-errors-list').remove();
        }
    });

    // Handle resource type change to show/hide resource title field
    $(document).on('change', '.course-chapter-resource-type', function() {
        var resourceType = $(this).val();
        var resourceRow = $(this).closest('.resource-input-section');
        var resourceTitleField = resourceRow.find('.resource-title-field');
        var resourceUrlField = resourceRow.find('.resource-url');
        var resourceFileField = resourceRow.find('.resource-file');
        var resourceTitleInput = resourceRow.find('.resource-title-input');
        var resourceUrlInput = resourceRow.find('.resource-url-input');
        var resourceFileInput = resourceRow.find('.resource-file-input');

        // Check if resource toggle is ON
        var resourceToggleOn = $('#resource-toggle').is(':checked');

        // Always show title field when resource type is selected
        if (resourceType && resourceType !== '') {
            resourceTitleField.show();

            // Add required to title if toggle is ON (but NOT data-parsley-required to prevent Parsley)
            if (resourceToggleOn) {
                resourceTitleInput.attr('required', 'required');
            }

            // Show appropriate input field based on type
            if (resourceType === 'url') {
                resourceUrlField.show();
                resourceFileField.hide();
                if (resourceToggleOn) {
                    resourceUrlInput.attr('required', 'required');
                    resourceFileInput.removeAttr('required').removeAttr('data-parsley-required');
                }
            } else if (resourceType === 'file') {
                resourceFileField.show();
                resourceUrlField.hide();
                if (resourceToggleOn) {
                    resourceFileInput.attr('required', 'required');
                    resourceUrlInput.removeAttr('required').removeAttr('data-parsley-required');
                }
            }
        } else {
            resourceTitleField.hide();
            resourceUrlField.hide();
            resourceFileField.hide();
            // Remove required when type is not selected
            resourceTitleInput.removeAttr('required').removeAttr('data-parsley-required');
            resourceUrlInput.removeAttr('required').removeAttr('data-parsley-required');
            resourceFileInput.removeAttr('required').removeAttr('data-parsley-required');
        }
    });

    // Handle repeater create to add required attributes for new items
    $(document).on('click', '[data-repeater-create]', function() {
        setTimeout(function() {
            var resourceToggleOn = $('#resource-toggle').is(':checked');
            if (resourceToggleOn) {
                $('[data-repeater-item]').each(function() {
                    var $row = $(this);
                    var resourceType = $row.find('.course-chapter-resource-type').val();
                    // Only add required if resource type is not selected yet (new item)
                    // Don't add data-parsley-required to prevent Parsley validation
                    if (!resourceType || resourceType === '') {
                        $row.find('.course-chapter-resource-type').attr('required', 'required');
                        $row.find('.resource-title-input').attr('required', 'required');
                    }
                });
            }
        }, 100);
    });

</script>

@endsection

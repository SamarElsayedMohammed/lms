@extends('layouts.app')

@section('title')
    {{ __('manage') . ' ' . __('courses') }}
@endsection

@push('style')
<style>
    /* Fix Select2 clear button and dropdown arrow positioning for Filter by Instructor */
    #filter_instructor_id + .select2-container .select2-selection--single {
        height: 38px;
        padding-right: 50px;
    }

    #filter_instructor_id + .select2-container .select2-selection--single .select2-selection__rendered {
        line-height: 38px;
        padding-left: 12px;
        padding-right: 35px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    #filter_instructor_id + .select2-container .select2-selection--single .select2-selection__arrow {
        height: 36px;
        right: 8px;
        width: 20px;
    }

    #filter_instructor_id + .select2-container .select2-selection--single .select2-selection__clear {
        position: absolute;
        right: 28px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        font-size: 16px;
        font-weight: bold;
        color: #999;
        z-index: 11;
        line-height: 1;
        padding: 2px 4px;
        width: 18px;
        height: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    #filter_instructor_id + .select2-container .select2-selection--single .select2-selection__clear:hover {
        color: #333;
        background-color: #f0f0f0;
        border-radius: 3px;
    }

    /* Ensure dropdown arrow doesn't overlap */
    #filter_instructor_id + .select2-container.select2-container--open .select2-selection--single .select2-selection__arrow {
        right: 8px;
    }
</style>
@endpush

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto"></div> @endsection

@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        @can('courses-create')
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('create') . ' ' . __('course') }}
                        </h4>

                        {{-- Form start --}}
                        <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('courses.store') }}" data-success-function="formSuccessFunction" data-parsley-validate enctype="multipart/form-data"> @csrf <div class="row">

                                {{-- Title --}}
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="title" class="form-label">{{ __('Title') }}</label>
                                    <input type="text" name="title" id="title" placeholder="{{ __('Title') }}" class="form-control" data-parsley-required="true">
                                </div>

                                {{-- Short Description --}}
                                <div class="form-group col-sm-12 col-md-6">
                                    <label>{{ __('Short Description') }}</label>
                                    <textarea name="short_description" id="short_description" class="form-control" placeholder="{{ __('Short Description') }}"></textarea>
                                </div>

                                {{-- Thumbnail --}}
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="thumbnail" class="form-label">{{ __('Thumbnail') }} <span class="text-danger">*</span></label>
                                    <input type="file" name="thumbnail" id="thumbnail" class="form-control" accept="image/*">
                                </div>

                                {{-- Intro Video --}}
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="intro_video" class="form-label">{{ __('Intro Video') }}</label>
                                    <input type="file" name="intro_video" id="intro_video" class="form-control" accept="video/*">
                                </div>

                                {{-- Level --}}
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="level" class="form-label">{{ __('Level') }} </label>
                                    <select name="level" id="level" class="form-control" data-parsley-required="true">
                                        <option value="beginner">{{ __('Beginner') }}</option>
                                        <option value="intermediate">{{ __('Intermediate') }}</option>
                                        <option value="advanced">{{ __('Advanced') }}</option>
                                    </select>
                                </div>

                                {{-- Course Type --}}
                                <div class="form-group mandatory col-sm-12 col-md-2">
                                    <label class="form-label d-block" for="course_type">{{ __('Course Type') }} </label>
                                    {{-- Free --}}
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="course-type-free" name="course_type" value="free" class="custom-control-input" required data-parsley-required="true">
                                        <label class="custom-control-label" for="course-type-free">{{ __('Free') }}</label>
                                    </div>
                                    {{-- Paid --}}
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="course-type-paid" name="course_type" value="paid" class="custom-control-input" data-parsley-required="true" checked>
                                        <label class="custom-control-label" for="course-type-paid">{{ __('Paid') }}</label>
                                    </div>
                                </div>



                                {{-- Certificate Toggle (Only for Free Courses) --}}
                                <div class="form-group col-sm-12 col-md-2 certificate-toggle-field" style="display: none;">
                                    <div class="control-label">
                                        {{ __('Certificate Available') }}
                                        <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip" data-placement="top" title="{{ __('Enable certificate generation for this free course. Students can get a certificate by paying the specified fee.') }}"></i>
                                    </div>
                                    <div class="custom-switches-stacked mt-2">
                                        <label class="custom-switch">
                                            <input type="checkbox" class="custom-switch-input" id="certificate_enabled" name="certificate_enabled" value="1">
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description certificate-enabled-text">{{ __('No') }}</span>
                                        </label>
                                    </div>
                                </div>

                                {{-- Certificate Fee (Only if Certificate is Enabled) --}}
                                <div class="form-group col-sm-12 col-md-2 certificate-fee-field" style="display: none;">
                                    <label class="form-label">
                                        {{ __('Certificate Fee') }}
                                        <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip" data-placement="top" title="{{ __('This fee will be charged to students who want to get a certificate for completing this free course') }}"></i>
                                    </label>
                                    <input type="number" name="certificate_fee" id="certificate_fee" step="0.01" min="0" placeholder="{{ __('Certificate Fee') }}" class="form-control">
                                </div>


                                {{-- Price --}}
                                <div class="form-group col-sm-12 col-md-6 price-field">
                                    <label for="price" class="form-label">{{ __('Price') }} </label>
                                    <input type="number" name="price" id="price" step="0.01" min="0" placeholder="{{ __('Price') }}" class="form-control" required>
                                </div>

                                {{-- Discount Price --}}
                                <div class="form-group col-sm-12 col-md-6 discount-price-field">
                                    <label>{{ __('Discount Price') }}</label>
                                    <input type="number" name="discount_price" id="discount-price" step="0.01" min="0" placeholder="{{ __('Discount Price') }}" class="form-control">
                                </div>

                                {{-- Category --}}
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="category" class="form-label">{{ __('Category') }} </label>
                                    <select name="category_id" id="category_id" class="form-select select2" required>
                                        <option value="">{{ __('Select a Category') }}</option>
                                        @include('categories.dropdowntree', ['categories' => $categories])
                                    </select>
                                </div>


                                {{-- Course Tags --}}
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="course_tags" class="form-label">{{ __('Course Tags') }}</label>
                                    <select name="course_tags[]" id="course_tags" class="form-control" multiple="multiple"> @foreach ($tags as $tag) <option value="{{ $tag->id }}">{{ $tag->tag }}</option> @endforeach </select>
                                    <small class="form-text text-muted">{{ __('Type and hit enter to add new tags or select from the list.') }}</small>
                                </div>

                                {{-- Language --}}
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="language-id" class="form-label">{{ __('Language') }}</label>
                                    <select name="language_id" id="language-id" class="form-control" required>
                                        <option value="">{{ __('Select a Language') }}</option> @foreach ($course_languages as $language) <option value="{{ $language->id }}">{{ $language->name }}</option> @endforeach </select>
                                </div>
                                <div><hr></div>

                                {{-- Course Learnings --}}
                                <div class="form-group mandatory col-12">
                                    <label class="form-label">{{ __('Course Learnings') }}</label>
                                    <div class="course-learnings-section">
                                        <div data-repeater-list="learnings_data">
                                            <div class="row learning-section d-flex align-items-center mb-2" data-repeater-item>
                                                <input type="hidden" name="id" class="id">
                                                {{-- Learning --}}
                                                <div class="form-group mandatory col-md-11">
                                                    <label class="form-label">{{ __('Learning') }} - <span class="learning-number"> {{ __('0') }} </span></label>
                                                    <input type="text" name="learning" class="form-control" placeholder="{{ __('Enter a learning outcome') }}" required data-parsley-required="true">
                                                </div>
                                                {{-- Remove Learning --}}
                                                <div class="form-group col-md-1 mt-4">
                                                    <button data-repeater-delete type="button" class="btn btn-danger remove-learning" title="{{ __('remove') }}">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        {{-- Add New Learning --}}
                                        <button type="button" class="btn btn-success mt-1 add-new-learning" data-repeater-create title="{{ __('Add New Learning') }}">
                                            <i class="fa fa-plus"></i> {{ __('Add New Learning') }}
                                        </button>
                                    </div>
                                </div>

                                <div><hr></div>
                                {{-- Course Requirements --}}
                                <div class="form-group mandatory col-12">
                                    <label class="form-label">{{ __('Course Requirements') }}</label>
                                    <div class="course-requirements-section">
                                        <div data-repeater-list="requirements_data">
                                            <div class="row learning-section d-flex align-items-center mb-2" data-repeater-item>
                                                <input type="hidden" name="id" class="id">
                                                {{-- Requirement --}}
                                                <div class="form-group mandatory col-md-11">
                                                    <label class="form-label">{{ __('Requirement') }} - <span class="requirement-number"> {{ __('0') }} </span></label>
                                                    <input type="text" name="requirement" class="form-control" placeholder="{{ __('Enter a requirement') }}" required data-parsley-required="true">
                                                </div>
                                                {{-- Remove Requirement --}}
                                                <div class="form-group col-md-1 mt-4">
                                                    <button data-repeater-delete type="button" class="btn btn-danger remove-requirement" title="{{ __('remove') }}">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        {{-- Add New Requirement --}}
                                        <button type="button" class="btn btn-success mt-1 add-new-requirement" data-repeater-create title="{{ __('Add New Requirement') }}">
                                            <i class="fa fa-plus"></i> {{ __('Add New Requirement') }}
                                        </button>
                                    </div>
                                </div>

                                <div><hr></div>

                                {{-- SEO Meta Tags Section --}}
                                <div class="form-group col-12">
                                    <h5 class="mb-3 text-primary"><i class="fas fa-tags mr-2"></i>{{ __('SEO Meta Tags') }}</h5>
                                </div>

                                {{-- Meta Title --}}
                                <div class="form-group col-12">
                                    <label for="meta_title" class="form-label">{{ __('Meta Title') }}</label>
                                    <input type="text" name="meta_title" id="meta_title" class="form-control" placeholder="{{ __('Enter meta title for SEO') }}" maxlength="60">
                                    <small class="form-text text-muted"><i class="fas fa-info-circle mr-1"></i>{{ __('SEO title for search engines (recommended: 50-60 characters)') }}</small>
                                </div>

                                {{-- Meta Description --}}
                                <div class="form-group col-12">
                                    <label for="meta_description" class="form-label">{{ __('Meta Description') }}</label>
                                    <textarea name="meta_description" id="meta_description" class="form-control" placeholder="{{ __('Enter meta description for SEO') }}" rows="3" maxlength="160"></textarea>
                                    <small class="form-text text-muted"><i class="fas fa-info-circle mr-1"></i>{{ __('SEO description for search engines (recommended: 150-160 characters)') }}</small>
                                </div>

                                {{-- Meta Keywords --}}
                                <div class="form-group col-12">
                                    <label for="meta_keywords" class="form-label">{{ __('Meta Keywords') }}</label>
                                    <textarea name="meta_keywords" id="meta_keywords" class="form-control" placeholder="{{ __('Enter keywords separated by commas (e.g., course, online, learning)') }}" rows="2"></textarea>
                                    <small class="form-text text-muted"><i class="fas fa-info-circle mr-1"></i>{{ __('Keywords for SEO (separate multiple keywords with commas)') }}</small>
                                </div>

                                <div><hr></div>

                                {{-- Sequential Chapter Access --}}
                                <div class="form-group col-sm-12 col-md-6">
                                    <div class="control-label">
                                        {{ __('Sequential Chapter Access') }}
                                        <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip" data-placement="top" title="{{ __('If enabled, students must complete chapters in order. If disabled, they can access any chapter freely.') }}"></i>
                                    </div>
                                    <div class="custom-switches-stacked mt-2">
                                        <label class="custom-switch">
                                            <input type="checkbox" class="custom-switch-input" id="sequential_access" name="sequential_access" value="1" checked>
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description sequential-access-text">{{ __('Sequential (Step by step)') }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit" value="{{ __('submit') }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>
        @endcan
        <!-- Table List -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        {{-- Title --}}
                        <h4 class="card-title">
                            {{ __('List Courses') }}
                        </h4>

                        {{-- Show Trash Button --}}
                        <div class="col-12 mt-4 text-right">
                            <b><a href="#" class="table-list-type active mr-2" data-id="0">{{ __('all') }}</a></b> {{ __('|') }} <a href="#" class="ml-2 table-list-type" data-id="1">{{ __('Trashed') }}</a>
                        </div>
                        <table aria-describedby="mydesc" class="table" id="table_list" data-table="courses"data-toggle="table" data-url="{{ route('courses.show', 0) }}" data-click-to-select="true" data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true" data-trim-on-search="false" data-mobile-responsive="true" data-use-row-attr-func="true" data-maintain-selected="true" data-export-data-type="all" data-export-options='{ "fileName": "{{ __('courses') }}-<?=
    date('d-m-y')
?>","ignoreColumn":["operate", "is_active"]}' data-show-export="true" data-query-params="courseQueryParams" data-table="course_chapters" data-status-column="is_active">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false" data-escape="true"> {{ __('id') }}</th>
                                    <th scope="col" data-field="no" data-escape="true">{{ __('no.') }}</th>
                                    <th scope="col" data-field="title" data-sortable="true" data-escape="true">{{ __('Title') }}</th>
                                    <th scope="col" data-field="short_description" data-sortable="true" data-visible="false" data-escape="true">{{ __('Short Description') }}</th>
                                    <th scope="col" data-field="course.learnings" data-sortable="false" data-visible="false" data-formatter="courseLearningsFormatter" data-escape="false"> {{ __('Learnings') }} </th>
                                    <th scope="col" data-field="course.requirements" data-sortable="false" data-visible="false" data-formatter="courseRequirementsFormatter" data-escape="false"> {{ __('Requirements') }} </th>
                                    <th scope="col" data-field="course.tags" data-sortable="false" data-visible="false" data-formatter="courseTagsFormatter" data-escape="false"> {{ __('Tags') }} </th>
                                    <th scope="col" data-field="thumbnail" data-formatter="imageFormatter" data-sortable="false" data-escape="false">{{ __('Thumbnail') }}</th>
                                    <th scope="col" data-field="intro_video" data-formatter="videoFormatter" data-sortable="false" data-escape="false">{{ __('Intro Video') }}</th>
                                    <th scope="col" data-field="user.name" data-sortable="true" data-formatter="capitalizeNameFormatter" data-escape="false">{{ __('Added By') }}</th>
                                    <th scope="col" data-field="level" data-sortable="true" data-formatter="capitalizeNameFormatter" data-escape="false">{{ __('Level') }}</th>
                                    <th scope="col" data-field="course_type" data-sortable="true" data-visible="true" data-formatter="capitalizeNameFormatter" data-escape="false">{{ __('Course Type') }}</th>
                                    <th scope="col" data-field="price" data-sortable="true" data-escape="true">{{ __('Price') }}</th>
                                    <th scope="col" data-field="discount_price" data-sortable="true" data-escape="true"> {{ __('Discount Price') }}</th>
                                    <th scope="col" data-field="category" data-sortable="false" data-visible="false" data-escape="true"> {{ __('Category') }} </th>
                                    <th scope="col" data-field="is_active" data-formatter="statusFormatter" data-export="false" data-escape="false"> {{ __('Is Active') }}</th>
                                    <th scope="col" data-field="is_active_export" data-visible="true" data-export="true" class="d-none">{{ __('Status (Export)') }}</th>
                                    <th scope="col" data-field="language.name" data-escape="true"> {{ __('Language') }}</th>
                                    <th scope="col" data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="" data-escape="false">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>

                        {{-- Filters Toolbar --}}
                        <div class="row mt-3 align-items-end" id="toolbar">
                            <div class="col-md-3">
                                <div class="form-group mb-0">
                                    <label class="form-label mb-1">{{ __('Filter by Active') }}</label>
                                    <select id="filter_is_active" class="form-control">
                                        <option value="">{{ __('All') }}</option>
                                        <option value="1">{{ __('Active') }}</option>
                                        <option value="0">{{ __('Inactive') }}</option>
                                    </select>
                                </div>
                            </div>
                            @if($shouldShowInstructorFilters ?? true)
                            <div class="col-md-4">
                                <div class="form-group mb-0">
                                    <label class="form-label mb-1">{{ __('Filter by Instructor') }}</label>
                                    <select id="filter_instructor_id" class="form-control select2" style="width: 100%;">
                                        <option value="">{{ __('All') }}</option>
                                        @foreach ($instructors as $instructor)
                                            <option value="{{ $instructor->id }}">{{ $instructor->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            @endif
                            <div class="col-md-auto">
                                <button type="button" class="btn btn-primary" id="apply_filters">
                                    <i class="fa fa-search mr-1"></i> {{ __('Apply') }}
                                </button>
                            </div>
                            <div class="col-md-auto">
                                <button type="button" class="btn btn-outline-secondary" id="reset_filters">{{ __('Reset') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Requests moved to dedicated page --}}
    </div> @endsection
@section('script')
    <script src="{{ asset('library/select2/dist/js/select2.full.min.js') }}"></script>
    <script>
        $(document).ready(function() {
            const $priceFields = $('.price-field');
            const $discountPriceFields = $('.discount-price-field');
            const $priceInput = $('#price');
            const $discountPriceInput = $('#discount-price');
            const $form = $('.create-form');

            function togglePriceFields() {
                if ($('#course-type-free').is(':checked')) {
                    $priceFields.hide();
                    $priceInput.removeAttr('required');
                    $discountPriceFields.hide();
                } else if ($('#course-type-paid').is(':checked')) {
                    $priceFields.show().addClass('mandatory');
                    $priceInput.attr('required', true);
                    $discountPriceFields.show();
                }
            }
            // Initial state
            togglePriceFields();
            // On change
            $('input[name="course_type"]').change(togglePriceFields);

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
                if ($('#course-type-free').is(':checked')) {
                    $certificateToggleField.show();
                } else {
                    $certificateToggleField.hide();
                    $certificateFeeField.hide();
                    $certificateToggle.prop('checked', false);
                    $certificateFeeInput.removeAttr('required').val('');
                }
            }

            $certificateToggle.on('change', updateCertificateToggle);
            $('input[name="course_type"]').change(toggleCertificateFields);

            // Initialize tooltips
            $('[data-toggle="tooltip"]').tooltip();

            // Initialize Select2 for instructor filter
            $('#filter_instructor_id').select2({
                placeholder: '{{ __("All") }}',
                allowClear: true,
                width: '100%'
            });

            // Apply filters on button click
            $('#apply_filters').on('click', function(){
                $('#table_list').bootstrapTable('refresh');
            });

            $('#reset_filters').on('click', function(){
                $('#filter_is_active').val('');
                $('#filter_instructor_id').val(null).trigger('change');
                $('#table_list').bootstrapTable('refresh');
            });

            // Ensure button height matches select2 height after Select2 is initialized
            setTimeout(function() {
                const select2Height = $('#filter_instructor_id').next('.select2-container').find('.select2-selection').outerHeight();
                if (select2Height) {
                    $('#reset_filters').css({
                        'height': select2Height + 'px',
                        'min-height': select2Height + 'px'
                    });
                }
            }, 200);

            // Requests filters
            $('#request_instructor_id').on('change', function(){
                $('#table_requests').bootstrapTable('refresh');
            });
            $('#reset_request_filters').on('click', function(){
                $('#request_instructor_id').val('').trigger('change');
                $('#table_requests').bootstrapTable('refresh');
            });
        });

        function formSuccessFunction(response){
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        }

        // Handle All/Trashed tab switching
        let showDeleted = 0;
        $('.table-list-type').on('click', function(e){
            e.preventDefault();
            $('.table-list-type').removeClass('active');
            $(this).addClass('active');
            showDeleted = $(this).data('id') === 1 ? 1 : 0;
            $('#table_list').bootstrapTable('refresh');
        });

        // Attach filters to table query params
        function courseQueryParams(params) {
            params.is_active = $('#filter_is_active').val();
            params.instructor_id = $('#filter_instructor_id').val();
            params.show_deleted = showDeleted;
            return params;
        }

        function requestQueryParams(params){
            params.instructor_id = $('#request_instructor_id').val();
            return params;
        }

        // Approve/Decline action buttons for requests table
        function requestOperateFormatter(value, row) {
            const viewBtn = `<a href="{{ url('courses') }}/${row.id}/edit" class="btn icon btn-xs btn-rounded btn-icon rounded-pill btn-info mr-1" title="{{ __('View') }}"><i class="fa fa-eye"></i></a>`;
            const approveBtn = `<button class="btn icon btn-xs btn-rounded btn-icon rounded-pill btn-success mr-1" onclick="approveCourse(${row.id}, 1)" title="{{ __('Approve') }}"><i class="fa fa-check"></i></button>`;
            const declineBtn = `<button class="btn icon btn-xs btn-rounded btn-icon rounded-pill btn-danger" onclick="approveCourse(${row.id}, 0)" title="{{ __('Decline') }}"><i class="fa fa-times"></i></button>`;
            return viewBtn + approveBtn + declineBtn;
        }

        function approveCourse(courseId, approve){
            const actionText = approve === 1 ? '{{ __('Approve') }}' : '{{ __('Decline') }}';
            const confirmText = approve === 1
                ? '{{ __("Are you sure you want to approve this course?") }}'
                : '{{ __("Are you sure you want to decline this course?") }}';

            Swal.fire({
                title: actionText + ' {{ __("Course") }}?',
                text: confirmText,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: approve === 1 ? '#28a745' : '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: actionText,
                cancelButtonText: '{{ __("Cancel") }}',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: `{{ url('courses') }}/${courseId}/approve`,
                        method: 'POST',
                        data: {
                            approve: approve,
                            _token: `{{ csrf_token() }}`
                        },
                        success: function(response){
                            Swal.fire({
                                icon: approve === 1 ? 'success' : 'warning',
                                title: approve === 1
                                    ? '{{ __("Course Approved") }}'
                                    : '{{ __("Course Declined") }}',
                                text: approve === 1
                                    ? '{{ __("Course has been approved successfully.") }}'
                                    : '{{ __("Course has been declined successfully.") }}',
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 3000,
                                timerProgressBar: true,
                                didOpen: (toast) => {
                                    toast.addEventListener('mouseenter', Swal.stopTimer)
                                    toast.addEventListener('mouseleave', Swal.resumeTimer)
                                }
                            });
                            $('#table_requests').bootstrapTable('refresh');
                            $('#table_list').bootstrapTable('refresh');
                        },
                        error: function(xhr){
                            let errorMessage = '{{ __("Something went wrong") }}';
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                errorMessage = xhr.responseJSON.message;
                            }
                            showSwalErrorToast(errorMessage, '', 4000);
                        }
                    });
                }
            });
        }

        // Hide Action column if no rows have any actions
        $('#table_list').on('load-success.bs.table', function (e, data) {
            if (data && data.rows) {
                const hasAnyActions = data.rows.some(row => row.operate && row.operate.trim() !== '');
                if (!hasAnyActions) {
                    $('#table_list').bootstrapTable('hideColumn', 'operate');
                } else {
                    $('#table_list').bootstrapTable('showColumn', 'operate');
                }
            }
        });

    </script>
@endsection

@push('style')
    <style>
        #table_list th[data-field="is_active_export"],
        #table_list td[data-field="is_active_export"] {
            display: none;
        }
    </style>
@endpush

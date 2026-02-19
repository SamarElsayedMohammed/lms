@extends('layouts.app')

@section('title')
    {{ __('Course Publish Requests') }}
@endsection

@push('style')
    <link rel="stylesheet" href="{{ asset('library/select2/dist/css/select2.min.css') }}">
@endpush

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1> @endsection

@section('main')
    <div class="content-wrapper">
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <div id="requestsToolbar" class="mb-3">
                            <div class="row align-items-end">
                                @if($shouldShowInstructorFilters ?? true)
                                <div class="col-md-6">
                                    <div class="form-group mb-0">
                                        <label class="form-label mb-1">{{ __('Filter by Instructor') }}</label>
                                        <select id="request_instructor_id" class="form-control select2" style="width: 100%;">
                                            <option value="">{{ __('All') }}</option>
                                            @foreach ($instructors as $instructor)
                                                <option value="{{ $instructor->id }}">{{ $instructor->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                @endif
                                <div class="col-md-auto">
                                    <button type="button" class="btn btn-primary" id="apply_request_filters">
                                        <i class="fa fa-search mr-1"></i> {{ __('Apply') }}
                                    </button>
                                </div>
                                <div class="col-md-auto">
                                    <button type="button" class="btn btn-outline-secondary" id="reset_request_filters">{{ __('Reset') }}</button>
                                </div>
                            </div>
                        </div>

                        <table class="table" id="table_requests" data-toggle="table" data-url="{{ route('courses.requests.list') }}" data-click-to-select="true" data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#requestsToolbar" data-show-refresh="true" data-trim-on-search="false" data-mobile-responsive="true" data-maintain-selected="true" data-query-params="requestQueryParams">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false" data-escape="true">{{ __('id') }}</th>
                                    <th scope="col" data-field="no" data-escape="true">{{ __('no.') }}</th>
                                    <th scope="col" data-field="title" data-sortable="true" data-escape="true">{{ __('Title') }}</th>
                                    <th scope="col" data-field="instructor_name" data-sortable="true" data-escape="true">{{ __('Instructor') }}</th>
                                    <th scope="col" data-field="category.name" data-sortable="true" data-escape="true">{{ __('Category') }}</th>
                                    <th scope="col" data-field="operate" data-formatter="requestOperateFormatter" data-escape="false">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div> @endsection

@section('script')
    <script src="{{ asset('library/select2/dist/js/select2.full.min.js') }}"></script>
    <script>
        $(document).ready(function(){
            // Initialize Select2
            $('#request_instructor_id').select2({
                placeholder: '{{ __("All") }}',
                allowClear: true,
                width: '100%'
            });

            // Apply filters on button click
            $('#apply_request_filters').on('click', function(){
                $('#table_requests').bootstrapTable('refresh');
            });

            // Reset filters
            $('#reset_request_filters').on('click', function(){
                $('#request_instructor_id').val(null).trigger('change.select2');
                $('#table_requests').bootstrapTable('refresh');
            });

            // Ensure button height matches select2 height after Select2 is initialized
            setTimeout(function() {
                const select2Height = $('#request_instructor_id').next('.select2-container').find('.select2-selection').outerHeight();
                if (select2Height) {
                    $('#reset_request_filters').css({
                        'height': select2Height + 'px',
                        'min-height': select2Height + 'px'
                    });
                }
            }, 200);
        });

        function requestQueryParams(params){
            params.instructor_id = $('#request_instructor_id').val();
            return params;
        }

        function requestOperateFormatter(value, row){
            const viewBtn = `<a href="{{ url('courses') }}/${row.id}/view" class="btn icon btn-xs btn-rounded btn-icon rounded-pill btn-info mr-1" title="{{ __('View') }}"><i class="fa fa-eye"></i></a>`;
            const approveBtn = `<button class="btn icon btn-xs btn-rounded btn-icon rounded-pill btn-success mr-1" onclick="approveCourse(${row.id}, 1)" title="{{ __('Approve') }}"><i class="fa fa-check"></i></button>`;
            const declineBtn = `<button class="btn icon btn-xs btn-rounded btn-icon rounded-pill btn-danger" onclick="approveCourse(${row.id}, 0)" title="{{ __('Decline') }}"><i class="fa fa-times"></i></button>`;
            return '<div class="action-column-menu">' + viewBtn + approveBtn + declineBtn + '</div>';
        }

        function approveCourse(courseId, approve){
            const action = approve === 1 ? '{{ __('approve') }}' : '{{ __('decline') }}';
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
                        data: { approve: approve, _token: `{{ csrf_token() }}` },
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
    </script>
@endsection

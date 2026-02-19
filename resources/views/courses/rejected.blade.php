@extends('layouts.app')

@section('title')
    {{ __('Rejected Courses') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1> @endsection

@section('main')
    <div class="content-wrapper">
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <div id="rejectedToolbar" class="mb-3">
                            <div class="row align-items-end">
                                @if($shouldShowInstructorFilters ?? true)
                                <div class="col-md-4">
                                    <div class="form-group mb-0">
                                        <label class="form-label mb-1">{{ __('Filter by Instructor') }}</label>
                                        <select id="rejected_instructor_id" class="form-control select2">
                                            <option value="">{{ __('All') }}</option>
                                            @foreach ($instructors as $instructor)
                                                <option value="{{ $instructor->id }}">{{ $instructor->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                @endif
                                <div class="col-md-auto">
                                    <button type="button" class="btn btn-primary" id="apply_rejected_filters">
                                        <i class="fa fa-search mr-1"></i> {{ __('Apply') }}
                                    </button>
                                </div>
                                <div class="col-md-auto">
                                    <button type="button" class="btn btn-outline-secondary" id="reset_rejected_filters">{{ __('Reset') }}</button>
                                </div>
                            </div>
                        </div>

                        <table class="table" id="table_rejected" data-toggle="table" data-url="{{ route('courses.rejected.list') }}" data-click-to-select="true" data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#rejectedToolbar" data-show-refresh="true" data-trim-on-search="false" data-mobile-responsive="true" data-maintain-selected="true" data-escape="true" data-query-params="rejectedQueryParams">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false">{{ __('id') }}</th>
                                    <th scope="col" data-field="no">{{ __('no.') }}</th>
                                    <th scope="col" data-field="title" data-sortable="true">{{ __('Title') }}</th>
                                    <th scope="col" data-field="instructor_name" data-sortable="false">{{ __('Instructor') }}</th>
                                    <th scope="col" data-field="category.name" data-sortable="false">{{ __('Category') }}</th>
                                    <th scope="col" data-field="created_at" data-sortable="true" data-formatter="rejectedDateFormatter">{{ __('Requested On') }}</th>
                                    <th scope="col" data-field="operate" data-formatter="rejectedOperateFormatter" data-escape="false">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div> @endsection

@push('style')
    <link rel="stylesheet" href="{{ asset('library/select2/dist/css/select2.min.css') }}">
@endpush

@section('script')
    <script src="{{ asset('library/select2/dist/js/select2.full.min.js') }}"></script>
    <script>
        $(document).ready(function(){
            // Initialize Select2
            $('#rejected_instructor_id').select2({
                placeholder: '{{ __("All") }}',
                allowClear: true,
                width: '100%'
            });

            // Apply filters on button click
            $('#apply_rejected_filters').on('click', function(){
                $('#table_rejected').bootstrapTable('refresh');
            });

            // Reset filters
            $('#reset_rejected_filters').on('click', function(){
                $('#rejected_instructor_id').val(null).trigger('change.select2');
                $('#table_rejected').bootstrapTable('refresh');
            });
        });

        function rejectedQueryParams(params){
            params.instructor_id = $('#rejected_instructor_id').val();
            return params;
        }

        function rejectedDateFormatter(value, row){
            if (row.created_at_human) {
                return `<span title="${row.created_at || ''}">${row.created_at_human}</span>`;
            }
            return value || '-';
        }

        function rejectedOperateFormatter(value, row){
            const viewBtn = `<a href="{{ url('courses') }}/${row.id}/edit" class="btn icon btn-xs btn-rounded btn-icon rounded-pill btn-info" title="{{ __('View') }}"><i class="fa fa-eye"></i></a>`;
            return '<div class="action-column-menu">' + viewBtn + '</div>';
        }
    </script>
@endsection

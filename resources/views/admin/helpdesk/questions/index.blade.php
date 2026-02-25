@extends('layouts.app')

@section('title')
{{ __('Manage Questions') }}
@endsection

@section('page-title')
<h1 class="mb-0">@yield('title')</h1>
@endsection

@section('main')
<div class="content-wrapper">
    <div class="row">
        <div class="col-md-12 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">
                        {{ __('Helpdesk Questions') }}
                    </h4>

                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card bg-primary text-white">
                                <div class="card-body">
                                    <h5 class="card-title">{{ __('Total Questions') }}</h5>
                                    <h3 id="total-questions">0</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-success text-white">
                                <div class="card-body">
                                    <h5 class="card-title">{{ __('Public') }}</h5>
                                    <h3 id="public-questions">0</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-warning text-white">
                                <div class="card-body">
                                    <h5 class="card-title">{{ __('Private') }}</h5>
                                    <h3 id="private-questions">0</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-info text-white">
                                <div class="card-body">
                                    <h5 class="card-title">{{ __('Recent') }}</h5>
                                    <h3 id="recent-questions">0</h3>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Table Type Tabs -->
                    <div class="mb-3">
                        <b><a href="#" class="table-list-type active mr-2" data-id="0">{{ __('All') }}</a></b> {{
                        __('|') }} <a href="#" class="ml-2 table-list-type" data-id="1">{{ __('Trashed') }}</a>
                    </div>

                    <!-- Filters -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <select class="form-control" id="group-filter">
                                <option value="">{{ __('All Groups') }}</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-control" id="type-filter">
                                <option value="">{{ __('All Types') }}</option>
                                <option value="0">{{ __('Public') }}</option>
                                <option value="1">{{ __('Private') }}</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="search-input"
                                placeholder="{{ __('Search') }}...">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary" id="search-btn">{{ __('Search') }}</button>
                        </div>
                    </div>

                    <!-- Data Table -->
                    <div class="table-responsive">
                        <table id="questions-table" class="table table-striped"
                            data-url="{{ route('admin.helpdesk.questions.index') }}" data-pagination="true"
                            data-side-pagination="server" data-page-list="[5, 10, 20, 50, 100, 200]" data-search="false"
                            data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                            data-trim-on-search="false" data-mobile-responsive="true" data-use-row-attr-func="true"
                            data-maintain-selected="true" data-export-data-type="all" data-export-options='{"fileName": "questions-<?=
    date(' d-m-y') ?>","ignoreColumn":["operate"]}'
                            data-show-export="true" data-query-params="queryParams">
                            <thead>
                                <tr>
                                    <th data-field="id" data-visible="false">{{ __('ID') }}</th>
                                    <th data-field="no">{{ __('No.') }}</th>
                                    <th data-field="title">{{ __('Title') }}</th>
                                    <th data-field="description">{{ __('Description') }}</th>
                                    <th data-field="group_name">{{ __('Group') }}</th>
                                    <th data-field="user_name">{{ __('User') }}</th>
                                    <th data-field="type" data-formatter="typeFormatter">{{ __('Type') }}</th>
                                    <th data-field="replies_count">{{ __('Replies') }}</th>
                                    <th data-field="created_at">{{ __('Created At') }}</th>
                                    <th data-field="operate" data-sortable="false"
                                        data-formatter="actionColumnFormatter" data-events="questionAction"
                                        data-escape="false">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('script')
<script>
    var currentListType = 0;

    $(document).ready(function () {
        $('#questions-table').bootstrapTable();
        loadStatistics();
        loadGroups();

        // Table type tabs
        $('.table-list-type').on('click', function (e) {
            e.preventDefault();
            currentListType = $(this).data('id');
            $('.table-list-type').removeClass('active').css('font-weight', 'normal');
            $(this).addClass('active').css('font-weight', 'bold');
            $('#questions-table').bootstrapTable('selectPage', 1);
            $('#questions-table').bootstrapTable('refresh');
        });

        $('#search-btn').click(function () {
            $('#questions-table').bootstrapTable('selectPage', 1);
            $('#questions-table').bootstrapTable('refresh');
        });

        $('#search-input').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#search-btn').click();
            }
        });
    });

    function queryParams(params) {
        params.group_id = $('#group-filter').val() || '';
        params.type = $('#type-filter').val() || '';
        params.search = $('#search-input').val() || '';
        params.list_type = currentListType;
        return params;
    }

    function typeFormatter(value, row, index) {
        if (value === 1 || value === true || value == '1') {
            return '<span class="badge badge-warning">{{ __("Private") }}</span>';
        }
        return '<span class="badge badge-success">{{ __("Public") }}</span>';
    }

    function loadStatistics() {
        $.get('{{ route("admin.helpdesk.questions.index") }}', { stats: 1 }, function (data) {
            if (data.stats) {
                $('#total-questions').text(data.stats.total);
                $('#public-questions').text(data.stats.public);
                $('#private-questions').text(data.stats.private);
                $('#recent-questions').text(data.stats.recent);
            }
        });
    }

    function loadGroups() {
        $.get('{{ route("groups.index") }}', function (data) {
            let options = '<option value="">{{ __("All Groups") }}</option>';
            data.rows.forEach(function (group) {
                options += `<option value="${group.id}">${group.name}</option>`;
            });
            $('#group-filter').html(options);
        });
    }

    window.questionAction = {
        'click .view-question': function (e, value, row) {
            window.location.href = `{{ url('admin/helpdesk/questions') }}/${row.id}`;
        },
        'click .delete-question': function (e, value, row) {
            Swal.fire({
                title: '{{ __("Are you sure?") }}',
                text: '{{ __("You will not be able to recover this record!") }}',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '{{ __("Yes, delete it!") }}',
                cancelButtonText: '{{ __("Cancel") }}'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: `{{ url('admin/helpdesk/questions') }}/${row.id}`,
                        type: 'DELETE',
                        data: { _token: '{{ csrf_token() }}' },
                        success: function (response) {
                            $('#questions-table').bootstrapTable('refresh');
                            loadStatistics();
                        }
                    });
                }
            });
        },
        'click .restore-question': function (e, value, row) {
            $.ajax({
                url: `{{ url('admin/helpdesk/questions') }}/${row.id}/restore`,
                type: 'POST',
                data: { _token: '{{ csrf_token() }}' },
                success: function (response) {
                    $('#questions-table').bootstrapTable('refresh');
                    loadStatistics();
                }
            });
        },
        'click .force-delete-question': function (e, value, row) {
            Swal.fire({
                title: '{{ __("Are you sure?") }}',
                text: '{{ __("This action cannot be undone!") }}',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '{{ __("Yes, delete it!") }}',
                cancelButtonText: '{{ __("Cancel") }}'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: `{{ url('admin/helpdesk/questions') }}/${row.id}/force-delete`,
                        type: 'DELETE',
                        data: { _token: '{{ csrf_token() }}' },
                        success: function (response) {
                            $('#questions-table').bootstrapTable('refresh');
                            loadStatistics();
                        }
                    });
                }
            });
        }
    };
</script>
@endsection
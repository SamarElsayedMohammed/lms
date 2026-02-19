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
                                        <h5 class="card-title">Total Questions</h5>
                                        <h3 id="total-questions">0</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-info text-white">
                                    <div class="card-body">
                                        <h5 class="card-title">Public</h5>
                                        <h3 id="public-questions">0</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-warning text-white">
                                    <div class="card-body">
                                        <h5 class="card-title">Private</h5>
                                        <h3 id="private-questions">0</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-success text-white">
                                    <div class="card-body">
                                        <h5 class="card-title">Recent</h5>
                                        <h3 id="recent-questions">0</h3>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- All/Trashed Tabs -->
                        <div class="col-12 mt-4 text-right mb-3">
                            <b><a href="#" class="table-list-type active mr-2" data-id="0">{{ __('All') }}</a></b> {{ __('|') }} <a href="#" class="ml-2 table-list-type" data-id="1">{{ __('Trashed') }}</a>
                        </div>

                        <!-- Filters -->
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <select class="form-control" id="group-filter">
                                    <option value="">All Groups</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-control" id="privacy-filter">
                                    <option value="">All Types</option>
                                    <option value="0">Public</option>
                                    <option value="1">Private</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <input type="text" class="form-control" id="search-input" placeholder="Search by title, description, user, or group...">
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-primary" id="search-btn">Search</button>
                            </div>
                        </div>

                        <!-- Data Table -->
                        <div class="table-responsive">
                            <table id="questions-table" class="table table-striped"
                                   data-url="{{ route('admin.helpdesk.questions.index') }}"
                                   data-pagination="true" data-side-pagination="server"
                                   data-page-list="[5, 10, 20, 50, 100, 200]" data-search="false"
                                   data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                                   data-trim-on-search="false" data-mobile-responsive="true"
                                   data-use-row-attr-func="true" data-maintain-selected="true"
                                   data-export-data-type="all" data-export-options='{"fileName": "questions-<?=
    date('d-m-y')
?>","ignoreColumn":["operate"]}'
                                   data-show-export="true" data-query-params="queryParams"
                                   data-sort-name="id" data-sort-order="desc">
                                <thead>
                                    <tr>
                                        <th data-field="id" data-visible="false">{{ __('ID') }}</th>
                                        <th data-field="no">{{ __('No.') }}</th>
                                        <th data-field="title">{{ __('Title') }}</th>
                                        <th data-field="description">{{ __('Description') }}</th>
                                        <th data-field="group_name">{{ __('Group') }}</th>
                                        <th data-field="user_name">{{ __('User') }}</th>
                                        <th data-field="is_private" data-formatter="privacyFormatter">{{ __('Type') }}</th>
                                        <th data-field="replies_count">{{ __('Replies') }}</th>
                                        <th data-field="created_at">{{ __('Created At') }}</th>
                                        <th data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="questionAction" data-escape="false" style="width: 120px; min-width: 120px;">{{ __('Action') }}</th>
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
$(document).ready(function() {
    // Initialize table
    $('#questions-table').bootstrapTable();

    // Load statistics
    loadStatistics();

    // Load groups for filter
    loadGroups();

    // Search functionality - only apply filters when Search button is clicked
    $('#search-btn').click(function() {
        // Reset to first page when searching
        $('#questions-table').bootstrapTable('selectPage', 1);
        $('#questions-table').bootstrapTable('refresh');
    });

    // Search on Enter key press
    $('#search-input').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            $('#search-btn').click();
        }
    });

    // Remove automatic filter on change - filters should only apply when Search button is clicked
    // Filters will be applied via queryParams function when Search button is clicked

    // Setup delete handler AFTER table is initialized
    setupDeleteHandler();

    // Handle All/Trashed tab switching
    $('.table-list-type').on('click', function(e){
        e.preventDefault();
        $('.table-list-type').removeClass('active');
        $(this).addClass('active');

        showDeleted = $(this).data('id') === 1 ? 1 : 0;

        // Refresh table
        $('#questions-table').bootstrapTable('refresh');
    });

    // Handle restore button clicks for questions table
    $(document).on('click', '#questions-table .restore-data', function(e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        const url = $(this).attr('href');

        showRestorePopupModal(url, {
            successCallBack: function () {
                $('#questions-table').bootstrapTable('refresh');
                loadStatistics();
            }
        });
        return false;
    });

    // Handle trash (permanent delete) button clicks for questions table
    $(document).on('click', '#questions-table .trash-data', function(e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        const url = $(this).attr('href');

        showPermanentlyDeletePopupModal(url, {
            successCallBack: function () {
                $('#questions-table').bootstrapTable('refresh');
                loadStatistics();
            }
        });
        return false;
    });
});

function setupDeleteHandler() {
    // Remove any existing delete handlers for questions table
    $(document).off('click', '#questions-table .delete-form');

    // Add custom delete handler that uses only SweetAlert (no confirm alert)
    $(document).on('click', '#questions-table .delete-form', function(e) {
        e.preventDefault();
        e.stopImmediatePropagation();

        const url = $(this).attr('href');

        // Use only SweetAlert modal - no confirm() alert
        showDeletePopupModal(url, {
            successCallBack: function () {
                $('#questions-table').bootstrapTable('refresh');
                loadStatistics();
            },
            errorCallBack: function (response) {
                if (response && response.message) {
                    showErrorToast(response.message);
                }
            }
        });

        return false;
    });
}

let showDeleted = 0;

function queryParams(params) {
    params.group_id = $('#group-filter').val() || '';
    params.is_private = $('#privacy-filter').val() || '';
    params.search = $('#search-input').val() || '';
    params.show_deleted = showDeleted;
    return params;
}

function privacyFormatter(value, row, index) {
    if (value == 1) {
        return '<span class="badge badge-warning">Private</span>';
    } else {
        return '<span class="badge badge-info">Public</span>';
    }
}

function loadStatistics() {
    $.get('{{ route("admin.helpdesk.questions.dashboard") }}', function(data) {
        $('#total-questions').text(data.total_questions);
        $('#public-questions').text(data.public_questions);
        $('#private-questions').text(data.private_questions);
        $('#recent-questions').text(data.recent_questions.length);
    });
}

function loadGroups() {
    $.get('{{ route("groups.index") }}', function(data) {
        let options = '<option value="">All Groups</option>';
        data.rows.forEach(function(group) {
            options += `<option value="${group.id}">${group.name}</option>`;
        });
        $('#group-filter').html(options);
    });
}

// Action events for Bootstrap Table
window.questionAction = {
    'click .view-question': function (e, value, row) {
        e.preventDefault();
        window.location.href = `{{ url('admin/helpdesk/questions') }}/${row.id}`;
    }
};

// Custom delete handler for questions table - override global handler
$(document).off('click', '#questions-table .delete-form'); // Remove any existing handlers
$(document).on('click', '#questions-table .delete-form', function(e) {
    e.preventDefault();
    e.stopImmediatePropagation(); // Stop global handler from also firing
    const url = $(this).attr('href');

    // Use global delete modal (SweetAlert) - only one modal will appear
    showDeletePopupModal(url, {
        successCallBack: function () {
            $('#questions-table').bootstrapTable('refresh');
            loadStatistics();
        },
        errorCallBack: function (response) {
            if (response && response.message) {
                showErrorToast(response.message);
            }
        }
    });
    return false; // Prevent further propagation
});

function showAlert(type, message) {
    let alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    let alertHtml = `<div class="alert ${alertClass} alert-dismissible fade show" role="alert">
        ${message}
        <button type="button" class="close" data-dismiss="alert">
            <span>&times;</span>
        </button>
    </div>`;

    $('.content-wrapper').prepend(alertHtml);
    setTimeout(function() {
        $('.alert').fadeOut();
    }, 3000);
}

// Hide Action column if no rows have any actions
$('#questions-table').on('load-success.bs.table', function (e, data) {
    if (data && data.rows) {
        const hasAnyActions = data.rows.some(row => row.operate && row.operate.trim() !== '');
        if (!hasAnyActions) {
            $('#questions-table').bootstrapTable('hideColumn', 'operate');
        } else {
            $('#questions-table').bootstrapTable('showColumn', 'operate');
        }
    }
});
</script>
@endsection

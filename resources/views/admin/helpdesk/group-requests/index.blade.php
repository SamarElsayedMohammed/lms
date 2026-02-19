@extends('layouts.app')

@section('title')
    {{ __('Manage Group Requests') }}
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
                            {{ __('Group Requests') }}
                        </h4>

                        <!-- Statistics Cards -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card bg-primary text-white">
                                    <div class="card-body">
                                        <h5 class="card-title">Total Requests</h5>
                                        <h3 id="total-requests">0</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-warning text-white">
                                    <div class="card-body">
                                        <h5 class="card-title">Pending</h5>
                                        <h3 id="pending-requests">0</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-success text-white">
                                    <div class="card-body">
                                        <h5 class="card-title">Approved</h5>
                                        <h3 id="approved-requests">0</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-danger text-white">
                                    <div class="card-body">
                                        <h5 class="card-title">Rejected</h5>
                                        <h3 id="rejected-requests">0</h3>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <select class="form-control" id="group-filter">
                                    <option value="">All Groups</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-control" id="status-filter">
                                    <option value="">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="search-input" placeholder="Search by user name, email, or group...">
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-primary" id="search-btn">Search</button>
                            </div>
                        </div>

                        <!-- Data Table -->
                        <div class="table-responsive">
                            <table id="group-requests-table" class="table table-striped"
                                   data-url="{{ route('admin.helpdesk.group-requests.index') }}"
                                   data-pagination="true" data-side-pagination="server"
                                   data-page-list="[5, 10, 20, 50, 100, 200]" data-search="false"
                                   data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                                   data-trim-on-search="false" data-mobile-responsive="true"
                                   data-use-row-attr-func="true" data-maintain-selected="true"
                                   data-export-data-type="all" data-export-options='{"fileName": "group-requests-<?=
    date('d-m-y')
?>","ignoreColumn":["operate"]}'
                                   data-show-export="true" data-query-params="queryParams">
                                <thead>
                                    <tr>
                                        <th data-field="id" data-visible="false">{{ __('ID') }}</th>
                                        <th data-field="no">{{ __('No.') }}</th>
                                        <th data-field="group_name">{{ __('Group') }}</th>
                                        <th data-field="user_name">{{ __('User Name') }}</th>
                                        <th data-field="user_email">{{ __('Email') }}</th>
                                        <th data-field="status" data-formatter="statusFormatter">{{ __('Status') }}</th>
                                        <th data-field="created_at">{{ __('Created At') }}</th>
                                        <th data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="groupRequestAction" data-escape="false">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal fade" id="statusUpdateModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Status</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="status-update-form">
                        @csrf
                        <input type="hidden" id="request-id" name="request_id">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control" name="status" id="status-select" required>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="update-status-btn">Update Status</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script>
$(document).ready(function() {
    // Initialize table
    $('#group-requests-table').bootstrapTable();

    // Load statistics
    loadStatistics();

    // Load groups for filter
    loadGroups();

    // Search functionality
    $('#search-btn').click(function() {
        // Reset to first page when searching
        $('#group-requests-table').bootstrapTable('selectPage', 1);
        $('#group-requests-table').bootstrapTable('refresh');
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

    // Status update
    $('#update-status-btn').click(function() {
        updateStatus();
    });
});

function queryParams(params) {
    params.group_id = $('#group-filter').val() || '';
    params.status = $('#status-filter').val() || '';
    params.search = $('#search-input').val() || '';
    return params;
}

function statusFormatter(value, row, index) {
    let badgeClass = 'badge-secondary';
    if (value === 'approved') badgeClass = 'badge-success';
    else if (value === 'rejected') badgeClass = 'badge-danger';
    else if (value === 'pending') badgeClass = 'badge-warning';

    return `<span class="badge ${badgeClass}">${value.charAt(0).toUpperCase() + value.slice(1)}</span>`;
}

function loadStatistics() {
    $.get('{{ route("admin.helpdesk.group-requests.dashboard") }}', function(data) {
        $('#total-requests').text(data.total_requests);
        $('#pending-requests').text(data.pending_requests);
        $('#approved-requests').text(data.approved_requests);
        $('#rejected-requests').text(data.rejected_requests);
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

function updateStatus() {
    let formData = $('#status-update-form').serialize();
    let requestId = $('#request-id').val();

    $.ajax({
        url: `{{ url('admin/helpdesk/group-requests') }}/${requestId}/status`,
        type: 'POST',
        data: formData,
        success: function(response) {
            // Check if response is successful (ResponseService uses 'error: false')
            if (response.error === false || response.success === true || response.success === 'true' || response.status === 'success' || (response.message && response.message.includes('successfully'))) {
                // Auto-hide modal
                $('#statusUpdateModal').modal('hide');

                // Refresh table and statistics
                $('#group-requests-table').bootstrapTable('refresh');
                loadStatistics();

                // Show success SweetAlert toast
                const Toast = Swal.mixin({
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

                Toast.fire({
                    icon: 'success',
                    title: response.message || 'Status updated successfully'
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: response.message || 'An error occurred',
                    confirmButtonText: 'OK'
                });
            }
        },
        error: function(xhr) {
            let errorMessage = 'An error occurred while updating status';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMessage = xhr.responseJSON.message;
            }

            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: errorMessage,
                confirmButtonText: 'OK'
            });
        }
    });
}

// Action events
window.groupRequestAction = {
    'click .view-request': function (e, value, row) {
        window.open(`{{ url('admin/helpdesk/group-requests') }}/${row.id}`, '_blank');
    },
    'click .update-status': function (e, value, row) {
        $('#request-id').val(row.id);
        $('#status-select').val(row.status);

        // Open modal directly without confirmation
        $('#statusUpdateModal').modal('show');
    }
};

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
$('#group-requests-table').on('load-success.bs.table', function (e, data) {
    if (data && data.rows) {
        const hasAnyActions = data.rows.some(row => row.operate && row.operate.trim() !== '');
        if (!hasAnyActions) {
            $('#group-requests-table').bootstrapTable('hideColumn', 'operate');
        } else {
            $('#group-requests-table').bootstrapTable('showColumn', 'operate');
        }
    }
});
</script>
@endsection

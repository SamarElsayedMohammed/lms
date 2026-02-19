@extends('layouts.app')

@section('title')
    {{ __('Manage Replies') }}
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
                            {{ __('Helpdesk Replies') }}
                        </h4>

                        <!-- Statistics Cards -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="card bg-primary text-white">
                                    <div class="card-body">
                                        <h5 class="card-title">Total Replies</h5>
                                        <h3 id="total-replies">0</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-success text-white">
                                    <div class="card-body">
                                        <h5 class="card-title">Recent Replies</h5>
                                        <h3 id="recent-replies">0</h3>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <select class="form-control select2" id="question-filter">
                                    <option value="">All Questions</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-control select2" id="user-filter">
                                    <option value="">All Users</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="search-input" placeholder="Search by reply content, user, or question...">
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-primary" id="search-btn">Search</button>
                            </div>
                        </div>

                        <!-- Data Table -->
                        <div class="table-responsive">
                            <table id="replies-table" class="table table-striped"
                                   data-url="{{ route('admin.helpdesk.replies.index') }}"
                                   data-pagination="true" data-side-pagination="server"
                                   data-page-list="[5, 10, 20, 50, 100, 200]" data-search="false"
                                   data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                                   data-trim-on-search="false" data-mobile-responsive="true"
                                   data-use-row-attr-func="true" data-maintain-selected="true"
                                   data-export-data-type="all" data-export-options='{"fileName": "replies-<?=
    date('d-m-y')
?>","ignoreColumn":["operate"]}'
                                   data-show-export="true" data-query-params="queryParams">
                                <thead>
                                    <tr>
                                        <th data-field="id" data-visible="false">{{ __('ID') }}</th>
                                        <th data-field="no">{{ __('No.') }}</th>
                                        <th data-field="reply">{{ __('Reply') }}</th>
                                        <th data-field="question_title">{{ __('Question') }}</th>
                                        <th data-field="user_name">{{ __('User') }}</th>
                                        <th data-field="parent_reply">{{ __('Parent Reply') }}</th>
                                        <th data-field="created_at">{{ __('Created At') }}</th>
                                        <th data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="replyAction" data-escape="false">{{ __('Action') }}</th>
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
    $('#replies-table').bootstrapTable();

    // Load statistics
    loadStatistics();

    // Load filters
    loadQuestions();
    loadUsers();

    // Search functionality - only apply filters when Search button is clicked
    $('#search-btn').click(function() {
        // Reset to first page when searching
        $('#replies-table').bootstrapTable('selectPage', 1);
        $('#replies-table').bootstrapTable('refresh');
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
});

function queryParams(params) {
    params.question_id = $('#question-filter').val() || '';
    params.user_id = $('#user-filter').val() || '';
    params.search = $('#search-input').val() || '';
    return params;
}

function loadStatistics() {
    $.get('{{ route("admin.helpdesk.replies.dashboard") }}', function(data) {
        $('#total-replies').text(data.total_replies);
        $('#recent-replies').text(data.recent_replies.length);
    });
}

function loadQuestions() {
    $.get('{{ route("admin.helpdesk.questions.index") }}', function(data) {
        let options = '<option value="">All Questions</option>';
        data.rows.forEach(function(question) {
            options += `<option value="${question.id}">${question.title}</option>`;
        });
        $('#question-filter').html(options);
    });
}

function loadUsers() {
    // TODO: This would need a users API endpoint
    $('#user-filter').html('<option value="">All Users</option>');
}

// Action events
window.replyAction = {
    'click .view-reply': function (e, value, row) {
        e.preventDefault();
        window.location.href = `{{ url('admin/helpdesk/replies') }}/${row.id}`;
    },
    'click .edit-reply': function (e, value, row) {
        e.preventDefault();
        window.location.href = `{{ url('admin/helpdesk/replies') }}/${row.id}/edit`;
    },
    'click .delete-reply': function (e, value, row) {
        e.preventDefault();
        deleteReply(row.id);
        return false;
    }
};

function deleteReply(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: '{{ __("Are you sure?") }}',
            text: @json(__("You won't be able to revert this!")),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: '{{ __("Yes, delete it!") }}',
            cancelButtonText: '{{ __("Cancel") }}'
        }).then((result) => {
            if (result.isConfirmed) {
                performDelete(id);
            }
        });
    } else {
        if (confirm('{{ __("Are you sure you want to delete this reply?") }}')) {
            performDelete(id);
        }
    }
}

function performDelete(id) {
    $.ajax({
        url: `{{ url('admin/helpdesk/replies') }}/${id}`,
        type: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        data: {
            _token: '{{ csrf_token() }}'
        },
        dataType: 'json',
        success: function(response) {
            // Check for different response formats
            const isSuccess = (response.success === true || response.success === 'true' || response.error === false || (response.error !== undefined && response.error === false));
            const message = response.message || '{{ __("Reply deleted successfully") }}';

            if (isSuccess) {
                $('#replies-table').bootstrapTable('refresh');
                loadStatistics();

                // Use toast notification in right corner
                if (typeof showSwalSuccessToast === 'function') {
                    showSwalSuccessToast(message);
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        text: message,
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true
                    });
                } else {
                    alert(message);
                }
            } else {
                const errorMsg = response.message || '{{ __("Failed to delete reply") }}';
                if (typeof showSwalErrorToast === 'function') {
                    showSwalErrorToast(errorMsg);
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        text: errorMsg,
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 4000,
                        timerProgressBar: true
                    });
                } else {
                    alert(errorMsg);
                }
            }
        },
        error: function(xhr) {
            let errorMessage = '{{ __("An error occurred while deleting reply") }}';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMessage = xhr.responseJSON.message;
            } else if (xhr.responseText) {
                try {
                    const errorData = JSON.parse(xhr.responseText);
                    if (errorData.message) {
                        errorMessage = errorData.message;
                    }
                } catch (e) {
                    // Ignore parse errors
                }
            }

            if (typeof showSwalErrorToast === 'function') {
                showSwalErrorToast(errorMessage);
            } else if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    text: errorMessage,
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 4000,
                    timerProgressBar: true
                });
            } else {
                alert(errorMessage);
            }
        }
    });
}

// Hide Action column if no rows have any actions
$('#replies-table').on('load-success.bs.table', function (e, data) {
    if (data && data.rows) {
        const hasAnyActions = data.rows.some(row => row.operate && row.operate.trim() !== '');
        if (!hasAnyActions) {
            $('#replies-table').bootstrapTable('hideColumn', 'operate');
        } else {
            $('#replies-table').bootstrapTable('showColumn', 'operate');
        }
    }
});
</script>
@endsection

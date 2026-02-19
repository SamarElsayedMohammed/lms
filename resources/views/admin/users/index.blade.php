@extends('layouts.app')

@section('title')
    {{ __('Users Management') }}
@endsection

@section('page-title')
    <h1 class="mb-0">{{ __('Users Management') }}</h1>
@endsection

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">
                        {{ __('List Users') }}
                    </h4>
                    <div id="toolbar"></div>

                    <table class="table table-bordered" id="table_list"
                        data-toggle="table"
                        data-url="{{ route('admin.users.show', 0) }}"
                        data-side-pagination="server"
                        data-pagination="true"
                        data-page-list="[5, 10, 20, 50, 100, 200]"
                        data-status-column="status"
                        data-search="true"
                        data-toolbar="#toolbar"
                        data-show-columns="true"
                        data-show-refresh="true"
                        data-trim-on-search="false"
                        data-mobile-responsive="true"
                        data-use-row-attr-func="true"
                        data-maintain-selected="true"
                        data-export-data-type="all"
                        data-export-options='{ "fileName": "{{ __('users') }}-<?= date('d-m-y') ?>","ignoreColumn":["operate", "is_active"]}'
                        data-show-export="true"
                        data-query-params="queryParams">
                        <thead>
                            <tr>
                                <th data-field="id" data-sortable="true" data-visible="false" data-escape="true">{{ __('ID') }}</th>
                                <th data-field="no" data-sortable="false" data-escape="true">{{ __('No.') }}</th>
                                <th data-field="name" data-sortable="true" data-escape="true">{{ __('Name') }}</th>
                                <th data-field="email" data-sortable="true" data-escape="true">{{ __('Email') }}</th>
                                <th data-field="mobile" data-sortable="true" data-escape="true">{{ __('Mobile') }}</th>
                                <th data-field="type" data-sortable="true" data-escape="true">{{ __('Type') }}</th>
                                <th data-field="is_active" data-sortable="true" data-formatter="statusFormatter" data-export="false" data-escape="false">{{ __('Status') }}</th>
                                <th data-field="is_active_export" data-visible="true" data-export="true" class="d-none">{{ __('Status (Export)') }}</th>
                                <th data-field="created_at" data-sortable="true" data-formatter="dateFormatter" data-escape="true">{{ __('Created At') }}</th>
                                <th data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="userEvents" data-escape="false">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- User Details Modal -->
    <div class="modal fade" id="userDetailsModal" tabindex="-1" role="dialog" aria-labelledby="userDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userDetailsModalLabel">{{ __('User Details') }}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                        <span aria-hidden="true" style="font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff;">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="userDetailsContent">
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

@section('style')
    <style>
        #table_list th[data-field="is_active_export"],
        #table_list td[data-field="is_active_export"] {
            display: none;
        }
    </style>
@endsection

@section('script')
<script>
    // Define the event handler for Bootstrap Table
    window.userEvents = {
        'click .view_btn': function (e, value, row) {
            e.preventDefault();
            loadUserDetails(row.id);
        },
    };

    function queryParams(params) {
        return params;
    }

    function statusFormatter(value, row) {
        let checked = (value == 1 || value == true) ? 'checked' : '';
        let isInstructor = row.is_instructor == 1 ? 'data-is-instructor="1"' : '';
        return `
            <div class="custom-control custom-switch custom-switch-2">
                <input type="checkbox" class="custom-control-input update-user-status" id="status_${row.id}" ${checked} data-user-id="${row.id}" ${isInstructor}>
                <label class="custom-control-label" for="status_${row.id}">&nbsp;</label>
            </div>
        `;
    }

    function dateFormatter(value, row, index) {
        // If value is already formatted (from server), return as is
        if (value && typeof value === 'string' && value.includes(',')) {
            return value;
        }
        // If value is ISO date string, format it
        if (value) {
            try {
                const date = new Date(value);
                if (!isNaN(date.getTime())) {
                    return date.toLocaleString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: true
                    });
                }
            } catch (e) {
                // If parsing fails, return original value
            }
        }
        return value || 'N/A';
    }

    function loadUserDetails(userId) {
        $('#userDetailsContent').html('<div class="text-center"><div class="spinner-border" role="status"><span class="sr-only">{{ __('Loading...') }}</span></div></div>');
        $('#userDetailsModal').modal('show');

        $.ajax({
            url: '{{ route("admin.users.details", ":id") }}'.replace(':id', userId),
            method: 'GET',
            success: function(response) {
                let html = '<div class="row">';
                html += '<div class="col-md-6"><strong>{{ __('ID') }}:</strong> ' + response.id + '</div>';
                html += '<div class="col-md-6"><strong>{{ __('Name') }}:</strong> ' + (response.name || 'N/A') + '</div>';
                html += '<div class="col-md-6"><strong>{{ __('Email') }}:</strong> ' + (response.email || 'N/A') + '</div>';
                html += '<div class="col-md-6"><strong>{{ __('Mobile') }}:</strong> ' + (response.mobile || 'N/A') + '</div>';
                html += '<div class="col-md-6"><strong>{{ __('Type') }}:</strong> ' + (response.type || 'N/A') + '</div>';
                html += '<div class="col-md-6"><strong>{{ __('Status') }}:</strong> ' + (response.is_active ? '{{ __('Active') }}' : '{{ __('Inactive') }}') + '</div>';
                html += '<div class="col-md-6"><strong>{{ __('Country Calling Code') }}:</strong> ' + (response.country_calling_code || 'N/A') + '</div>';
                html += '<div class="col-md-6"><strong>{{ __('Country Code') }}:</strong> ' + (response.country_code || 'N/A') + '</div>';
                html += '<div class="col-md-6"><strong>{{ __('Slug') }}:</strong> ' + (response.slug || 'N/A') + '</div>';
                html += '<div class="col-md-6"><strong>{{ __('Wallet Balance') }}:</strong> ' + (response.wallet_balance || '0') + '</div>';
                html += '<div class="col-md-6"><strong>{{ __('Created At') }}:</strong> ' + (response.created_at || 'N/A') + '</div>';
                html += '<div class="col-md-6"><strong>{{ __('Updated At') }}:</strong> ' + (response.updated_at || 'N/A') + '</div>';
                if (response.deleted_at) {
                    html += '<div class="col-md-12"><strong>{{ __('Deleted At') }}:</strong> ' + response.deleted_at + '</div>';
                }
                if (response.roles && response.roles.length > 0) {
                    html += '<div class="col-md-12"><strong>{{ __('Roles') }}:</strong> ';
                    response.roles.forEach(function(role, index) {
                        html += role.name;
                        if (index < response.roles.length - 1) html += ', ';
                    });
                    html += '</div>';
                }
                html += '</div>';
                $('#userDetailsContent').html(html);
            },
            error: function() {
                $('#userDetailsContent').html('<div class="alert alert-danger">{{ __('Failed to load user details') }}</div>');
            }
        });
    }


    // Handle status toggle change
    $(document).on('change', '.update-user-status', function () {
        let userId = $(this).data('user-id');
        let isInstructor = $(this).data('is-instructor') == 1;
        let currentStatus = $(this).is(':checked') ? 1 : 0;
        let newStatus = currentStatus;

        let confirmMessage = 'Are you sure you want to ' + (newStatus == 1 ? 'activate' : 'deactivate') + ' this user?';

        if (isInstructor) {
            confirmMessage += '\n\nNote: This user is also an instructor. ';
            if (newStatus == 1) {
                confirmMessage += 'Activating will also activate the instructor account on both user and instructor sides.';
            } else {
                confirmMessage += 'Deactivating will also suspend the instructor account on both user and instructor sides.';
            }
        }

        if (confirm(confirmMessage)) {
            $.ajax({
                url: '{{ route("admin.users.toggle-status", ":id") }}'.replace(':id', userId),
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                data: {
                    is_active: newStatus
                },
                success: function(response) {
                    if (response.success) {
                        $('#table_list').bootstrapTable('refresh');
                        alert(response.message);
                    } else {
                        alert('Failed to update user status');
                        // Revert checkbox
                        $('#status_' + userId).prop('checked', !newStatus);
                    }
                },
                error: function(xhr) {
                    let errorMsg = 'Failed to update user status';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    alert(errorMsg);
                    // Revert checkbox
                    $('#status_' + userId).prop('checked', !newStatus);
                }
            });
        } else {
            // Revert checkbox if user cancels
            $(this).prop('checked', !currentStatus);
        }
    });

    // Add row number
    $(document).ready(function() {
        $('#table_list').on('load-success.bs.table', function() {
            var rows = $(this).find('tbody tr');
            rows.each(function(index) {
                $(this).find('td:first').text(index + 1);
            });
        });
    });
</script>
@endsection

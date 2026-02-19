@extends('layouts.app')

@section('title')
    {{ __('Certificate Management') }}
@endsection

@section('page-title')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
        <h1 class="mb-2 mb-md-0 flex-shrink-0">@yield('title')</h1>
        <div class="section-header-button w-100 w-md-auto" style="margin-left: auto;">
            <a href="{{ route('admin.certificates.create') }}" class="btn btn-primary btn-block btn-sm-md">
                <i class="fas fa-plus"></i> <span class="d-none d-sm-inline">{{ __('Create New Certificate') }}</span>
                <span class="d-sm-none">{{ __('Create') }}</span>
            </a>
        </div>
    </div>
@endsection

@section('main')
<div class="section">
    <div class="row">
        <div class="col-12">
            <div class="card">
               
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-border" id="table_list" data-toggle="table" 
                            data-url="{{ route('admin.certificates.index') }}" 
                            data-pagination="true"
                            data-side-pagination="server" 
                            data-search="true" 
                            data-toolbar="#toolbar"
                            data-page-list="[5, 10, 20, 50, 100]" 
                            data-show-columns="true" 
                            data-show-refresh="true"
                            data-sort-name="id" 
                            data-sort-order="desc" 
                            data-mobile-responsive="true"
                            data-table="certificates" 
                            data-show-export="true"
                            data-export-options='{"fileName": "certificate-list","ignoreColumn": ["operate"]}'
                            data-export-types="['pdf','json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']">
                            <thead>
                                <tr>
                                    <th data-field="id" data-align="center" data-sortable="true">{{ __('ID') }}</th>
                                    <th data-field="name" data-sortable="true">{{ __('Name') }}</th>
                                    <th data-field="type_display" data-sortable="false" data-formatter="typeFormatter">{{ __('Type') }}</th>
                                    <th data-field="title" data-sortable="true">{{ __('Title') }}</th>
                                    <th data-field="is_active" data-align="center" data-sortable="true" data-formatter="certificateStatusFormatter">{{ __('Status') }}</th>
                                    <th data-field="created_at" data-sortable="true" data-formatter="dateFormatter">{{ __('Created At') }}</th>
                                    <th data-field="operate" data-align="center" data-formatter="actionColumnFormatter">{{ __('Action') }}</th>
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

@push('scripts')
<script>
function typeFormatter(value, row, index) {
    let badgeClass = 'badge-success';
    if (row.type === 'exam_completion') {
        badgeClass = 'badge-info';
    } else if (row.type === 'custom') {
        badgeClass = 'badge-warning';
    }
    return '<span class="badge ' + badgeClass + '">' + value + '</span>';
}

function certificateStatusFormatter(value, row, index) {
    let checked = (value == 1 || value == true) ? 'checked' : '';
    return `
        <div class="custom-control custom-switch custom-switch-2">
            <input type="checkbox" class="custom-control-input update-certificate-status" id="status_${row.id}" ${checked} data-certificate-id="${row.id}">
            <label class="custom-control-label" for="status_${row.id}">&nbsp;</label>
        </div>
    `;
}

function dateFormatter(value, row, index) {
    return value || '';
}

// Custom delete handler for certificates - uses SweetAlert
$(document).on('click', '#table_list .delete-form', function(e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    
    const url = $(this).attr('href');
    
    // Use global delete modal (SweetAlert)
    showDeletePopupModal(url, {
        successCallBack: function () {
            // Refresh table after successful delete
            $('#table_list').bootstrapTable('refresh');
        },
        errorCallBack: function (response) {
            if (response && response.message) {
                showErrorToast(response.message);
            }
        }
    });
    
    return false;
});

// Custom status toggle handler for certificates
$(document).on('change', '.update-certificate-status', function() {
    const certificateId = $(this).data('certificate-id');
    const checkbox = $(this);
    const originalState = checkbox.is(':checked');
    
    $.ajax({
        url: `{{ route('admin.certificates.toggle-status', ':id') }}`.replace(':id', certificateId),
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
        success: function(response) {
            // ResponseService returns {error: false, message: "...", ...}
            if (!response.error) {
                showSuccessToast(response.message);
                $('#table_list').bootstrapTable('refresh');
            } else {
                showErrorToast(response.message || 'Failed to update certificate status');
                // Revert the toggle
                checkbox.prop('checked', !originalState);
            }
        },
        error: function(xhr) {
            let errorMessage = 'An error occurred while updating certificate status';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMessage = xhr.responseJSON.message;
            } else if (xhr.responseText) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.message) {
                        errorMessage = response.message;
                    }
                } catch (e) {
                    console.error('Error parsing response:', e);
                }
            }
            showErrorToast(errorMessage);
            // Revert the toggle
            checkbox.prop('checked', !originalState);
        }
    });
});
</script>
@endpush

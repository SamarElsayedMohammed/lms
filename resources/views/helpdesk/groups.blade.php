@extends('layouts.app')

@section('title')
    {{ __('Manage Help Desk Groups') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1> @endsection

@section('main')
    <div class="content-wrapper">

    <!-- Create Form -->
    @can('helpdesk-groups-create')
    <div class="row">
        <div class="col-md-12 grid-margin stretch-card search-container">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">
                        {{ __('Create Group') }}
                    </h4>
                    <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('groups.store') }}" enctype="multipart/form-data" data-parsley-validate> @csrf <div class="row">
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Name') }} <span class="text-danger"> * </span></label>
                                <input type="text" name="name" class="form-control" required>
                            </div>
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Description') }}</label>
                                <input type="text" name="description" class="form-control">
                            </div>
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Image') }}</label>
                                <input type="file" name="image" class="form-control" accept="image/jpg,image/jpeg,image/png,image/svg">
                                <small class="text-muted">{{ __('Accepted formats: JPG, PNG, JPEG, SVG. Max size: 2MB') }}</small>
                            </div>
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Privacy') }}</label>
                                <div class="form-check">
                                    <input type="checkbox" name="is_private" value="1" class="form-check-input" id="is_private">
                                    <label class="form-check-label" for="is_private">
                                        {{ __('Private Group') }}
                                    </label>
                                </div>
                                <small class="text-muted">{{ __('Private groups require approval to join') }}</small>
                            </div>
                        </div>
                        <input class="btn btn-primary float-right ml-3" type="submit" value="{{ __('Submit') }}">
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
                    <h4 class="card-title">
                        {{ __('List Groups') }}
                    </h4>
                    <table aria-describedby="mydesc" class="table reorder-table-row" id="table_list"
                        data-table="helpdesk_groups" data-toggle="table" data-status-column="is_active"
                        data-url="{{ route('groups.show', 0) }}" data-click-to-select="true"
                        data-side-pagination="server" data-pagination="true"
                        data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#toolbar"
                        data-show-columns="true" data-show-refresh="true" data-trim-on-search="false"
                        data-mobile-responsive="true" data-use-row-attr-func="true" data-reorderable-rows="true"
                        data-maintain-selected="true" data-export-data-type="all"
                        data-export-options='{ "fileName": "{{ __('groups') }}-<?= date('d-m-y') ?>","ignoreColumn":["operate"]}'
                        data-show-export="true" data-query-params="queryParams">
                        <thead>
                            <tr>
                                <th data-field="id" data-visible="false">{{ __('id') }}</th>
                                <th data-field="no">{{ __('No.') }}</th>
                                <th data-field="name">{{ __('Name') }}</th>
                                <th data-field="description">{{ __('Description') }}</th>
                                <th data-field="image" data-formatter="imageFormatter">{{ __('Image') }}</th>
                                <th data-field="is_private" data-formatter="privacyFormatter">{{ __('Privacy') }}</th>
                                <th data-field="row_order">{{ __('Row Order') }}</th>
                                <th data-field="is_active" data-formatter="statusFormatter">{{ __('Status') }}</th>
                                <th data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="helpdeskGroupAction" data-escape="false">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                    </table>
                    <span class="d-block mb-4 mt-2 text-danger small">
                        {{ __('Note :- you can change the rank of rows by dragging rows') }}
                    </span>
                    <button id="change-order-helpdesk-groups" class="btn btn-primary">{{ __('Update Rank') }}</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="groupEditModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-md" role="document">
            <div class="modal-content">
                <form method="POST" class="edit-form" id="groupEditForm" enctype="multipart/form-data"> @csrf
                    @method('PUT')
        <div class="modal-header">
                        <h5 class="modal-title">{{ __('Edit Group') }}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                            <span aria-hidden="true" style="font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff;">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_group_id">
                        <div class="form-group">
                            <label>{{ __('Name') }}</label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>{{ __('Description') }}</label>
                            <input type="text" name="description" id="edit_description" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>{{ __('Image') }}</label>
                            <input type="file" name="image" id="edit_image" class="form-control" accept="image/jpg,image/jpeg,image/png,image/svg">
                            <small class="text-muted">{{ __('Accepted formats: JPG, PNG, JPEG, SVG. Max size: 2MB') }}</small>
                            <div id="current_image_preview" class="mt-2" style="display: none;">
                                <label>{{ __('Current Image:') }}</label>
                                <img id="current_image" src="" alt="Current Image" style="max-width: 100px; max-height: 100px;" class="img-thumbnail">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>{{ __('Privacy') }}</label>
                            <div class="form-check">
                                <input type="checkbox" name="is_private" value="1" class="form-check-input" id="edit_is_private">
                                <label class="form-check-label" for="edit_is_private">
                                    {{ __('Private Group') }}
                                </label>
                            </div>
                            <small class="text-muted">{{ __('Private groups require approval to join') }}</small>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <input class="btn btn-primary" type="submit" value="{{ __('Update') }}">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Cancel') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
// Image formatter for table
function imageFormatter(value, row, index) {
    if (value && value !== '') {
        return '<img src="' + value + '" alt="Group Image" style="max-width: 50px; max-height: 50px;" class="img-thumbnail">';
    }
    return '<span class="text-muted">No Image</span>';
}

// Status formatter for table
function statusFormatter(value, row, index) {
    if (value == 1) {
        return '<span class="badge badge-success">Active</span>';
    } else {
        return '<span class="badge badge-danger">Inactive</span>';
    }
}

// Privacy formatter for table
function privacyFormatter(value, row, index) {
    if (value == 1) {
        return '<span class="badge badge-warning">Private</span>';
    } else {
        return '<span class="badge badge-info">Public</span>';
    }
}

// Wait for window load to ensure all scripts are loaded
window.addEventListener('load', function() {
    // Check if jQuery is available
    if (typeof $ === 'undefined') {
        console.error('jQuery is not loaded');
        return;
    }

    console.log('jQuery loaded successfully');

    // Handle edit modal population
    $(document).on('click', '.edit-data', function(e) {
        e.preventDefault();

        var id = $(this).data('id');
        var name = $(this).data('name');
        var description = $(this).data('description');
        var image = $(this).data('image');
        var isPrivate = $(this).data('is-private');

        // Populate modal fields
        $('#edit_group_id').val(id);
        $('#edit_name').val(name);
        $('#edit_description').val(description);
        $('#edit_is_private').prop('checked', isPrivate == 1);

        // Handle current image preview
        if (image && image !== '') {
            $('#current_image').attr('src', image);
            $('#current_image_preview').show();
        } else {
            $('#current_image_preview').hide();
        }

        // Update form action
        $('#groupEditForm').attr('action', '/helpdesk/groups/' + id);
    });



    // Update rank functionality
    $('#change-order-helpdesk-groups').click(function() {
        var selectedRows = $('#table_list').bootstrapTable('getSelections');
        if (selectedRows.length === 0) {
            alert('Please select rows to update rank');
            return;
        }

        var ids = selectedRows.map(function(row) {
            return row.id;
        });

        $.ajax({
            url: '/helpdesk/groups/update-rank',
            type: 'POST',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content'),
                ids: ids
            },
            success: function(response) {
                if (response.status) {
                    $('#table_list').bootstrapTable('refresh');
                    alert('Rank updated successfully');
                } else {
                    alert('Error updating rank');
                }
            },
            error: function() {
                alert('Error updating rank');
            }
        });
    });

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
});
</script>

@endsection

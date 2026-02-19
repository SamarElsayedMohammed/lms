@extends('layouts.app')

@section('title')
    {{ __('Staff Management') }}
@endsection

@section('page-title')
    <h1 class="mb-0">{{ __('Staff Management') }}</h1>
    {{-- @can('role-create'))) --}}
        <div class="section-header-button ml-auto">
            <a href="{{ route('staffs.create') }}" class="btn btn-primary">
                + {{ __('Create New Staff') }}
            </a>
        </div>
    {{-- @endcan --}}
@endsection

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">
                        {{ __('List Staffs') }}
                    </h4>
                    <div id="toolbar"></div>

                    <table class="table table-bordered" id="table_list" data-toggle="table" data-url="{{ route('staffs.show',0) }}"
                        data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100]" data-status-column="is_active"
                        data-search="true" data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true" data-table="users"
                        data-fixed-columns="true" data-fixed-number="1" data-fixed-right-number="1" data-sort-name="id"
                        data-sort-order="desc" data-query-params="queryParams" data-show-export="true"
                        data-export-types="['csv', 'excel', 'pdf']"
                        data-export-options='{"fileName": "staff-list","ignoreColumn": ["operate"]}'
                        data-status-column="deleted_at" data-mobile-responsive="true">
                        <thead>
                            <tr>
                                <th data-field="id" data-sortable="true" data-align="center">{{ __('ID') }}</th>
                                <th data-field="name" data-sortable="true" data-align="center">{{ __('Name') }}</th>
                                <th data-field="email" data-sortable="true" data-align="center">{{ __('Email') }}</th>
                                {{-- @can('staff-update'))) --}}
                                <th data-field="is_active" data-align="center" data-formatter="statusFormatter">
                                    {{ __('Status') }}</th>
                                {{-- @endcan --}}
                                {{-- @canany(['staff-update', 'staff-delete']) --}}
                                <th data-field="operate" data-align="center" data-formatter="actionColumnFormatter" data-events="staffEvents" data-escape="false">
                                    {{ __('Action') }}</th>
                                {{-- @endcanany --}}
                            </tr>
                        </thead>
                    </table>

                </div>
            </div>
        </div>
    </div>

    {{-- Edit Modal --}}
    {{-- @can('staff-update'))) --}}
        <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <form class="edit-form" method="POST" data-parsley-validate> @csrf <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('Edit Staff') }}</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                                <span aria-hidden="true" style="font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff;">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="edit_role">{{ __('Role') }}</label>
                                <select name="role_id" id="edit_role" class="form-control" required>
                                    <option value="">{{ __('-- Select Role --') }}</option> @foreach ($roles as $role) <option value="{{ $role->id }}" data-role-name="{{ $role->name }}">{{ $role->name }}</option> @endforeach </select>
                            </div>

                            <div class="form-group mt-2">
                                <label for="edit_name">{{ __('Name') }}</label>
                                <input type="text" id="edit_name" name="name" class="form-control" required>
                            </div>

                            <div class="form-group mt-2">
                                <label for="edit_email">{{ __('Email') }}</label>
                                <input type="email" id="edit_email" name="email" class="form-control" required>
                            </div>

                            <div id="edit-supervisor-permissions" class="form-group mt-3" style="display: none;">
                                <label class="form-label">{{ __('Supervisor Permissions') }}</label>
                                <div class="row">
                                    @foreach($supervisorPermissions ?? [] as $perm)
                                    <div class="col-md-4 col-lg-3">
                                        <label class="custom-switch mt-2">
                                            <input type="checkbox" name="supervisor_permissions[]" value="{{ $perm }}" class="custom-switch-input edit-perm-{{ $perm }}">
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description">{{ __(ucfirst(str_replace('_', ' ', $perm))) }}</span>
                                        </label>
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary"
                                data-dismiss="modal">{{ __('Close') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    {{-- @endcan --}}

    {{-- Reset Password Modal --}}
    {{-- @can('staff-update'))) --}}
        <div class="modal fade" id="resetPasswordModel" tabindex="-1" aria-labelledby="resetPasswordModelLabel"
            aria-hidden="true">
            <div class="modal-dialog">
                <form class="edit-form" method="POST" data-parsley-validate> @csrf <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('Password Reset') }}</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                                <span aria-hidden="true" style="font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff;">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="new_password">{{ __('New Password') }}</label>
                                <input type="password" name="new_password" id="new_password" class="form-control" data-parsley-required-message="Password is required"
                                    data-parsley-required="true" required>
                            </div>

                            <div class="form-group mt-2">
                                <label for="confirm_password">{{ __('Confirm Password') }}</label>
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" data-parsley-required-message="Confirm Password is required"
                                    data-parsley-equalto="#new_password" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary"
                                data-dismiss="modal">{{ __('Close') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    {{-- @endcan --}}

@push('scripts')
<script>
(function() {
    var SUPERVISOR_ROLE = '{{ config('constants.SYSTEM_ROLES.SUPERVISOR') }}';
    $(document).ready(function() {
        $('#edit_role').on('change', function() {
            var isSupervisor = $(this).find('option:selected').data('role-name') === SUPERVISOR_ROLE;
            $('#edit-supervisor-permissions').toggle(isSupervisor);
        });
    });
    var origEdit = window.staffEvents['click .edit_btn'];
    window.staffEvents['click .edit_btn'] = function(e, value, row) {
        origEdit && origEdit.call(this, e, value, row);
        var isSupervisor = $('#edit_role option:selected').data('role-name') === SUPERVISOR_ROLE;
        $('#edit-supervisor-permissions').toggle(isSupervisor);
        $('[name="supervisor_permissions[]"]').prop('checked', false);
        if (row.permissions && Array.isArray(row.permissions)) {
            row.permissions.forEach(function(p) {
                $('.edit-perm-' + p).prop('checked', true);
            });
        }
    };
})();
</script>
@endpush
@endsection

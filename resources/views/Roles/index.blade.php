@extends('layouts.app')

@section('title')
    {{ __('Role Management') }}
@endsection

@section('page-title')
    <h1 class="mb-0">{{ __('Role Management') }}</h1>
    <div class="section-header-button ml-auto"> @can('roles-create')<a class="btn btn-primary" href="{{ route('roles.create') }}"> + {{ __('Create New Role') }}</a>@endcan</div> @endsection

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">
                            {{ __('List Roles')}}
                        </h4>
                    <div class="table-responsive">
                        <table class="table table-border" id="table_list" data-toggle="table" data-url="{{ route('roles.list',0) }}"
                            data-pagination="true" data-side-pagination="server" data-search="true" data-toolbar="#toolbar"
                            data-page-list="[5, 10, 20, 50, 100, 200]" data-show-columns="true" data-show-refresh="true"
                            data-sort-name="id" data-sort-order="desc" data-show-columns="true"
                            data-mobile-responsive="true" data-table="roles" data-show-export="true"
                            data-export-options='{ "fileName": "roles-list-<?= date('d-m-y') ?>", "ignoreColumn":
                            ["operate"] }'
                            data-export-types="['pdf', 'json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']"
                            data-query-params="queryParams">
                            <thead>
                                <tr>
                                    <th data-field="id" data-align="center" data-sortable="true" data-visible="false">
                                        {{ __('ID') }}</th>
                                    <th data-field="no" data-align="center">{{ __('No.') }}</th>
                                    <th data-field="name" data-sortable="true">{{ __('Name') }}</th>
                                    <th data-field="operate" data-align="center" data-formatter="actionColumnFormatter" data-escape="false">{{ __('Action') }}
                                    </th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script>
    // Hide Action column if no rows have any actions (all operate fields are empty)
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
</script>
@endsection

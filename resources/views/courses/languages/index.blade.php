@extends('layouts.app')

@section('title')
    {{ __('Manage Languages') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
    </div> @endsection

@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        @can('course-languages-create')
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('Create Language') }}
                        </h4>
                        <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('courses.languages.store') }}" data-parsley-validate>
                            <div class="row">
                                <div class="form-group col-sm-12 col-md-5">
                                    <label>{{ __('name') }} <span class="text-danger"> * </span></label>
                                    <input type="text" name="name" id="name" placeholder="{{ __('name') }}" class="form-control" required>
                                </div>
                                <div class="form-group col-sm-12 col-md-2">
                                    <label class="d-block">{{ __('Status') }}</label>
                                    <div class="custom-switches-stacked mt-2">
                                        <label class="custom-switch" for="customSwitch1">
                                            <input type="hidden" name="is_active" value="0">
                                            <input type="checkbox" name="is_active" id="customSwitch1" value="1"
                                                class="custom-switch-input">
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description">{{ __('Active') }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit"
                                value="{{ __('Submit') }}">
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
                            {{ __('List Languages') }}
                        </h4>
                        <div class="col-12 mt-4 text-right">
                            <b><a href="#" class="table-list-type active mr-2"
                                    data-id="0">{{ __('all') }}</a></b> {{ __('|') }} <a href="#"
                                class="ml-2 table-list-type" data-id="1">{{ __('Trashed') }}</a>
                        </div>
                        <table aria-describedby="mydesc" class="table reorder-table-row" id="table_list"
                            data-table="course_languages" data-toggle="table" data-status-column="is_active"
                            data-url="{{ route('courses.languages.list',0) }}" data-click-to-select="true"
                            data-side-pagination="server" data-pagination="true"
                            data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#toolbar"
                            data-show-columns="true" data-show-refresh="true" data-trim-on-search="false"
                            data-mobile-responsive="true" data-use-row-attr-func="true"
                            data-maintain-selected="true" data-export-data-type="all"
                            data-export-options='{ "fileName": "{{ __('languages') }}-<?= date('d-m-y') ?>","ignoreColumn":["operate", "is_active"]}'
                            data-show-export="true" data-query-params="courseLanguagesQueryParams">
                                <thead>
                                    <tr>
                                        <th scope="col" data-field="id" data-sortable="true" data-visible="false" data-escape="true">{{ __('id') }}</th>
                                        <th scope="col" data-field="no" data-escape="true">{{ __('no.') }}</th>
                                        <th scope="col" data-field="name" data-sortable="true" data-escape="true">{{ __('name') }}</th>
                                        <th scope="col" data-field="is_active" data-formatter="statusFormatter" data-export="false" data-escape="false">{{ __('status') }}</th>
                                        <th scope="col" data-field="is_active_export" data-visible="true" data-export="true" class="d-none">{{ __('Status (Export)') }}</th>
                                        <th scope="col" data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="courseLanguageEvents" data-escape="false"> {{ __('action') }}</th>
                                    </tr>
                                </thead>
                            </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div class="modal fade" id="languageEditModal" tabindex="-1" role="dialog"
            aria-labelledby="languageEditModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-md" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="languageEditModalLabel">{{ __('Edit Language')}}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                            <span aria-hidden="true" style="font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff;">&times;</span>
                        </button>
                    </div>
                    <form class="pt-3 mt-6 edit-form" method="POST" data-parsley-validate id="languageEditForm"> @csrf
                        @method('PUT')
        <div class="modal-body">
                            <input type="hidden" name="id" id="edit_language_id">
                            <div class="form-group">
                                <label>{{ __('name') }} <span class="text-danger"> * </span></label>
                                <input type="text" name="name" id="edit_name" placeholder="{{ __('name') }}" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="d-block">{{ __('Status') }}</label>
                                <div class="custom-switches-stacked mt-2">
                                    <label class="custom-switch" for="edit_customSwitch1">
                                        <input type="hidden" name="is_active" value="0">
                                        <input type="checkbox" name="is_active" id="edit_customSwitch1" value="1" class="custom-switch-input">
                                        <span class="custom-switch-indicator"></span>
                                        <span class="custom-switch-description">{{ __('Active') }}</span>
                                    </label>
                                </div>
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
@endsection

@section('script')
<script>
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
</script>
@endsection

@section('style')
    <style>
        #table_list th[data-field="is_active_export"],
        #table_list td[data-field="is_active_export"] {
            display: none;
        }
    </style>
@endsection

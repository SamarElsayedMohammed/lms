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
                                    <h5 class="card-title">{{ __('Total Replies') }}</h5>
                                    <h3 id="total-replies">0</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-info text-white">
                                <div class="card-body">
                                    <h5 class="card-title">{{ __('Recent Replies') }}</h5>
                                    <h3 id="recent-replies">0</h3>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <select class="form-control" id="question-filter">
                                <option value="">{{ __('All Questions') }}</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-control" id="user-filter">
                                <option value="">{{ __('All Users') }}</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="text" class="form-control" id="search-input"
                                placeholder="{{ __('Search') }}...">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary" id="search-btn">{{ __('Search') }}</button>
                        </div>
                    </div>

                    <!-- Data Table -->
                    <div class="table-responsive">
                        <table id="replies-table" class="table table-striped"
                            data-url="{{ route('admin.helpdesk.replies.index') }}" data-pagination="true"
                            data-side-pagination="server" data-page-list="[5, 10, 20, 50, 100, 200]" data-search="false"
                            data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                            data-trim-on-search="false" data-mobile-responsive="true" data-use-row-attr-func="true"
                            data-maintain-selected="true" data-export-data-type="all" data-export-options='{"fileName": "replies-<?=
    date(' d-m-y') ?>","ignoreColumn":["operate"]}'
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
                                    <th data-field="operate" data-sortable="false"
                                        data-formatter="actionColumnFormatter" data-events="replyAction"
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
    $(document).ready(function () {
        $('#replies-table').bootstrapTable();
        loadStatistics();
        loadQuestions();
        loadUsers();

        $('#search-btn').click(function () {
            $('#replies-table').bootstrapTable('selectPage', 1);
            $('#replies-table').bootstrapTable('refresh');
        });

        $('#search-input').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#search-btn').click();
            }
        });
    });

    function queryParams(params) {
        params.question_id = $('#question-filter').val() || '';
        params.user_id = $('#user-filter').val() || '';
        params.search = $('#search-input').val() || '';
        return params;
    }

    function loadStatistics() {
        $.get('{{ route("admin.helpdesk.replies.index") }}', { stats: 1 }, function (data) {
            if (data.stats) {
                $('#total-replies').text(data.stats.total);
                $('#recent-replies').text(data.stats.recent);
            }
        });
    }

    function loadQuestions() {
        $.get('{{ route("admin.helpdesk.questions.index") }}', { all: 1 }, function (data) {
            let options = '<option value="">{{ __("All Questions") }}</option>';
            if (data.rows) {
                data.rows.forEach(function (question) {
                    options += `<option value="${question.id}">${question.title}</option>`;
                });
            }
            $('#question-filter').html(options);
        });
    }

    function loadUsers() {
        $.get('{{ url("admin/users") }}', { all: 1 }, function (data) {
            let options = '<option value="">{{ __("All Users") }}</option>';
            if (data.rows) {
                data.rows.forEach(function (user) {
                    options += `<option value="${user.id}">${user.name}</option>`;
                });
            }
            $('#user-filter').html(options);
        });
    }

    window.replyAction = {
        'click .view-reply': function (e, value, row) {
            window.location.href = `{{ url('admin/helpdesk/replies') }}/${row.id}`;
        },
        'click .delete-reply': function (e, value, row) {
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
                        url: `{{ url('admin/helpdesk/replies') }}/${row.id}`,
                        type: 'DELETE',
                        data: { _token: '{{ csrf_token() }}' },
                        success: function (response) {
                            $('#replies-table').bootstrapTable('refresh');
                            loadStatistics();
                        }
                    });
                }
            });
        }
    };
</script>
@endsection
@extends('layouts.app')

@section('title')
    {{ __('Subscription Plans') }}
@endsection

@section('page-title')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
        <h1 class="mb-2 mb-md-0 flex-shrink-0">@yield('title')</h1>
        @can('subscription-plans-create')
        <div class="section-header-button w-100 w-md-auto" style="margin-left: auto;">
            <a href="{{ route('subscription-plans.create') }}" class="btn btn-primary btn-block btn-sm-md">
                <i class="fas fa-plus"></i> <span class="d-none d-sm-inline">{{ __('Create Plan') }}</span>
                <span class="d-sm-none">{{ __('Create') }}</span>
            </a>
        </div>
        @endcan
    </div>
@endsection

@section('main')
<div class="section">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="col-12 mb-3 text-right">
                        <b><a href="#" class="table-list-type active mr-2" data-id="0">{{ __('All') }}</a></b>
                        {{ __('|') }}
                        <a href="#" class="ml-2 table-list-type" data-id="1">{{ __('Trashed') }}</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-border" id="table_list" data-toggle="table"
                            data-url="{{ route('subscription-plans.index') }}"
                            data-pagination="true"
                            data-side-pagination="server"
                            data-search="true"
                            data-toolbar="#toolbar"
                            data-page-list="[5, 10, 20, 50, 100]"
                            data-show-columns="true"
                            data-show-refresh="true"
                            data-sort-name="sort_order"
                            data-sort-order="asc"
                            data-mobile-responsive="true"
                            data-table="subscription_plans"
                            data-show-export="true"
                            data-export-options='{"fileName": "subscription-plans-<?= date("d-m-y") ?>","ignoreColumn": ["operate"]}'
                            data-query-params="subscriptionPlanQueryParams">
                            <thead>
                                <tr>
                                    <th data-field="id" data-visible="false">{{ __('ID') }}</th>
                                    <th data-field="no" data-sortable="false">{{ __('No.') }}</th>
                                    <th data-field="name" data-sortable="true">{{ __('Plan Name') }}</th>
                                    <th data-field="price_formatted" data-sortable="false">{{ __('Price') }}</th>
                                    <th data-field="billing_cycle_label" data-sortable="false">{{ __('Duration') }}</th>
                                    <th data-field="commission_rate" data-sortable="true">{{ __('Commission %') }}</th>
                                    <th data-field="active_subscribers_count" data-sortable="false">{{ __('Subscribers') }}</th>
                                    <th data-field="is_active_display" data-sortable="false">{{ __('Status') }}</th>
                                    <th data-field="sort_order" data-sortable="true">{{ __('Order') }}</th>
                                    <th data-field="operate" data-align="center" data-sortable="false" data-formatter="actionColumnFormatter">{{ __('Action') }}</th>
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
let showDeleted = 0;

$('.table-list-type').on('click', function(e) {
    e.preventDefault();
    $('.table-list-type').removeClass('active');
    $(this).addClass('active');
    showDeleted = $(this).data('id') === 1 ? 1 : 0;
    $('#table_list').bootstrapTable('refresh');
});

function subscriptionPlanQueryParams(params) {
    params.show_deleted = showDeleted;
    return params;
}

function actionColumnFormatter(value, row, index) {
    return value || '';
}

// Toggle status handler
$(document).on('click', '#table_list .toggle-status', function(e) {
    e.preventDefault();
    const url = $(this).attr('href');
    const btn = $(this);

    $.ajax({
        url: url,
        method: 'POST',
        data: {
            _token: '{{ csrf_token() }}',
        },
        success: function(response) {
            if (response && !response.error) {
                $('#table_list').bootstrapTable('refresh');
                if (typeof showSuccessToast === 'function') {
                    showSuccessToast(response.message || '{{ __("Updated") }}');
                } else {
                    alert(response.message || '{{ __("Updated") }}');
                }
            }
        },
        error: function(xhr) {
            const msg = xhr.responseJSON?.message || '{{ __("Failed to update") }}';
            if (typeof showErrorToast === 'function') {
                showErrorToast(msg);
            } else {
                alert(msg);
            }
        }
    });
    return false;
});

// Delete handler - use common delete modal if available
$(document).on('click', '#table_list .delete-form', function(e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    const url = $(this).attr('href');

    if (typeof showDeletePopupModal === 'function') {
        showDeletePopupModal(url, {
            successCallBack: function() {
                $('#table_list').bootstrapTable('refresh');
            }
        });
    } else if (confirm('{{ __("Are you sure you want to delete this plan?") }}')) {
        $.ajax({
            url: url,
            method: 'DELETE',
            data: { _token: '{{ csrf_token() }}' },
            success: function() {
                $('#table_list').bootstrapTable('refresh');
            }
        });
    }
    return false;
});

// Restore handler
$(document).on('click', '#table_list .restore-data', function(e) {
    e.preventDefault();
    const url = $(this).attr('href');
    if (confirm('{{ __("Restore this plan?") }}')) {
        $.ajax({
            url: url,
            method: 'PUT',
            data: { _token: '{{ csrf_token() }}' },
            success: function() {
                $('#table_list').bootstrapTable('refresh');
            }
        });
    }
    return false;
});

// Trash (permanent delete) handler
$(document).on('click', '#table_list .trash-data', function(e) {
    e.preventDefault();
    const url = $(this).attr('href');
    if (confirm('{{ __("Permanently delete? This cannot be undone.") }}')) {
        $.ajax({
            url: url,
            method: 'DELETE',
            data: { _token: '{{ csrf_token() }}' },
            success: function() {
                $('#table_list').bootstrapTable('refresh');
            }
        });
    }
    return false;
});
</script>
@endpush

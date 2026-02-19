@extends('layouts.app')

@section('title')
    {{ __('Supervisor Wallet History') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
@endsection

@section('main')
    <div class="content-wrapper">
        <!-- Table List -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('Supervisor Wallet History') }}
                        </h4>
                        <p class="text-muted">{{ __('View all wallet transactions for supervisors including commissions, withdrawals, and other activities.') }}</p>

                        <!-- Filters -->
                        <div class="row mb-3 align-items-end">
                            <div class="col-md-4">
                                <div class="form-group mb-0">
                                    <label class="form-label mb-1">{{ __('Filter by Transaction Type') }}</label>
                                    <select id="filter_transaction_type" class="form-control">
                                        <option value="">{{ __('All Transactions') }}</option>
                                        <option value="credit">{{ __('Credit Only') }}</option>
                                        <option value="debit">{{ __('Debit Only') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-0">
                                    <label class="form-label mb-1">{{ __('Filter by Supervisor') }}</label>
                                    <select id="filter_instructor_id" class="form-control">
                                        <option value="">{{ __('All Supervisors') }}</option>
                                        @foreach ($instructors as $instructor)
                                            <option value="{{ $instructor->id }}">{{ $instructor->name }} ({{ $instructor->email }})</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-auto">
                                <button type="button" class="btn btn-primary" id="apply_filters">
                                    <i class="fa fa-search mr-1"></i> {{ __('Apply') }}
                                </button>
                            </div>
                            <div class="col-md-auto">
                                <button type="button" class="btn btn-outline-secondary" id="reset_filters">{{ __('Reset') }}</button>
                            </div>
                        </div>

                        <style>
                            /* Fix Select2 clear button and dropdown arrow positioning */
                            #filter_instructor_id + .select2-container .select2-selection--single {
                                height: 38px;
                                padding-right: 50px;
                            }

                            #filter_instructor_id + .select2-container .select2-selection--single .select2-selection__rendered {
                                line-height: 38px;
                                padding-left: 12px;
                                padding-right: 35px;
                                overflow: hidden;
                                text-overflow: ellipsis;
                                white-space: nowrap;
                            }

                            #filter_instructor_id + .select2-container .select2-selection--single .select2-selection__arrow {
                                height: 36px;
                                right: 8px;
                                width: 20px;
                            }

                            #filter_instructor_id + .select2-container .select2-selection--single .select2-selection__clear {
                                position: absolute;
                                right: 28px;
                                top: 50%;
                                transform: translateY(-50%);
                                cursor: pointer;
                                font-size: 16px;
                                font-weight: bold;
                                color: #999;
                                z-index: 11;
                                line-height: 1;
                                padding: 2px 4px;
                                width: 18px;
                                height: 18px;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                            }

                            #filter_instructor_id + .select2-container .select2-selection--single .select2-selection__clear:hover {
                                color: #333;
                                background-color: #f0f0f0;
                                border-radius: 3px;
                            }

                            /* Ensure dropdown arrow doesn't overlap */
                            #filter_instructor_id + .select2-container.select2-container--open .select2-selection--single .select2-selection__arrow {
                                right: 8px;
                            }
                        </style>

                        <table aria-describedby="mydesc" class="table" id="table_list"
                            data-toggle="table" data-url="{{ route('instructor.wallet-history.data') }}"
                            data-click-to-select="true" data-side-pagination="server" data-pagination="true"
                            data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#toolbar"
                            data-show-columns="true" data-show-refresh="true" data-trim-on-search="false"
                            data-mobile-responsive="true" data-use-row-attr-func="true"
                            data-maintain-selected="true" data-export-data-type="all"
                            data-export-options='{ "fileName": "{{ __('instructor-wallet-history') }}-<?= date('d-m-y') ?>","ignoreColumn":["operate"]}'
                            data-show-export="true" data-query-params="queryParams">
                            <thead>
                                <tr>
                                    <th data-field="id" data-visible="false">{{ __('ID') }}</th>
                                    <th data-field="no">{{ __('No.') }}</th>
                                    <th data-field="instructor_name">{{ __('Supervisor Name') }}</th>
                                    <th data-field="instructor_email">{{ __('Email') }}</th>
                                    <th data-field="type">{{ __('Type') }}</th>
                                    <th data-field="transaction_type">{{ __('Transaction Type') }}</th>
                                    <th data-field="entry_type">{{ __('Entry Type') }}</th>
                                    <th data-field="amount" data-sortable="true" data-escape="false">{{ __('Amount') }}</th>
                                    <th data-field="description">{{ __('Description') }}</th>
                                    <th data-field="created_at" data-sortable="true">{{ __('Date & Time') }}</th>
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
    // Query params function for bootstrap table - must be global
    function queryParams(params) {
        // Get transaction type filter value
        const transactionType = $('#filter_transaction_type').val() || '';
        const instructorId = $('#filter_instructor_id').val() || '';

        // Add filter parameters
        params.transaction_type = transactionType;
        params.instructor_id = instructorId;

        return params;
    }

    $(document).ready(function() {
        // Initialize tooltips
        $('[data-toggle="tooltip"]').tooltip();

        // Initialize select2 for instructor filter
        $('#filter_instructor_id').select2({
            placeholder: '{{ __("Select Supervisor") }}',
            allowClear: true,
            width: '100%'
        });

        // Apply filters on button click
        $('#apply_filters').on('click', function(){
            $('#table_list').bootstrapTable('refresh');
        });

        // Reset filters
        $('#reset_filters').on('click', function(){
            $('#filter_transaction_type').val('').trigger('change');
            // Clear Select2 properly
            $('#filter_instructor_id').val(null).trigger('change.select2');
            $('#table_list').bootstrapTable('refresh');
        });
    });
</script>
@endsection

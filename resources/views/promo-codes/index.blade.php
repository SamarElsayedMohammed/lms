@extends('layouts.app')

@section('title')
    {{ __('Manage Promo Codes') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
    </div> @endsection

@section('main')
    <div class="content-wrapper">

        <!-- Create Form -->
        @can('promo-codes-create')
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('Create Promo Code') }}
                        </h4>
                        <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('promo-codes.store') }}" data-parsley-validate> @csrf <div class="row">
                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Promo Code') }} <span class="text-danger"> * </span></label>
                                    <input type="text" name="promo_code" placeholder="PROMO10" class="form-control" required>
                                </div>

                                <div class="form-group col-sm-12 col-md-4">
                                    <label>{{ __('Message') }} <span class="text-danger"> * </span></label>
                                    <input type="text" name="message" placeholder="10% off on your next order" class="form-control" required>
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Start Date') }} <span class="text-danger"> * </span></label>
                                    <input type="date" name="start_date" id="start_date" class="form-control" required>
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('End Date') }} <span class="text-danger"> * </span></label>
                                    <input type="date" name="end_date" id="end_date" class="form-control" required>
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('No of Users') }} <span class="text-danger"> * </span></label>
                                    <input type="number" name="no_of_users" placeholder="e.g. 10" class="form-control" required>
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Discount Type') }} <span class="text-danger"> * </span></label>
                                    <select name="discount_type" class="form-control" required id="discount_type">
                                        <option value="percentage">{{ __('Percentage') }}</option>
                                        <option value="amount">{{ __('Fixed') }}</option>
                                    </select>
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Discount') }} <span class="text-danger"> * </span></label>
                                    <input type="number" name="discount" placeholder="e.g. 10" class="form-control" min="1" max="999999999" step="1" required>
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
                            {{ __('List Promo Codes') }}
                        </h4>
                        <div class="col-12 mt-4 text-right">
                            <b><a href="#" class="table-list-type active mr-2" data-id="0">{{ __('all') }}</a></b> {{ __('|') }} <a href="#" class="ml-2 table-list-type" data-id="1">{{ __('Trashed') }}</a>
                        </div>
                        <table aria-describedby="mydesc" class="table reorder-table-row" id="table_list"
                            data-table="promo_codes" data-toggle="table" data-status-column="status"
                            data-url="{{ route('promo-codes.show', 0) }}" data-click-to-select="true"
                            data-side-pagination="server" data-pagination="true"
                            data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#toolbar"
                            data-show-columns="true" data-show-refresh="true" data-trim-on-search="false"
                            data-mobile-responsive="true" data-use-row-attr-func="true"
                            data-maintain-selected="true" data-export-data-type="all"
                            data-export-options='{ "fileName": "{{ __('promo-codes') }}-<?= date('d-m-y') ?>","ignoreColumn":["operate", "status"]}'
                            data-show-export="true" data-query-params="queryParams">
                            <thead>
                                <tr>
                                    <th data-field="id" data-visible="false" data-escape="true">{{ __('id') }}</th>
                                    <th data-field="no" data-escape="true">{{ __('no.') }}</th>
                                    <th data-field="promo_code" data-escape="true">{{ __('Promo Code') }}</th>
                                    <th data-field="message" data-escape="true">{{ __('Message') }}</th>
                                    <th data-field="status" data-formatter="promoCodeStatusFormatter" data-export="false" data-escape="false" id="status-column">{{ __('Status') }}</th>
                                    <th data-field="status_export" data-visible="true" data-export="true" class="d-none">{{ __('Status (Export)') }}</th>
                                    <th data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="promoCodeAction" data-escape="false">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div class="modal fade" id="promoCodeEditModal" tabindex="-1" role="dialog"
            aria-labelledby="promoCodeEditModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="promoCodeEditModalLabel">{{ __('Edit Promo Code') }}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                            <span aria-hidden="true" style="font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff;">&times;</span>
                        </button>
                    </div>
                    <form class="pt-3 mt-6 edit-form" method="POST" data-parsley-validate id="promoCodeEditForm"> @csrf
                        @method('PUT')
        <div class="modal-body row">
                            <input type="hidden" name="id" id="edit_promo_code_id">

                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Promo Code') }} <span class="text-danger"> * </span></label>
                                <input type="text" name="promo_code" id="edit_promo_code" class="form-control" required>
                            </div>

                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Message') }} <span class="text-danger"> * </span></label>
                                <input type="text" name="message" id="edit_message" class="form-control" required>
                            </div>

                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Start Date') }} <span class="text-danger"> * </span></label>
                                <input type="date" name="start_date" id="edit_start_date" class="form-control" required>
                            </div>

                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('End Date') }} <span class="text-danger"> * </span></label>
                                <input type="date" name="end_date" id="edit_end_date" class="form-control" required>
                            </div>

                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('No of Users') }} <span class="text-danger"> * </span></label>
                                <input type="number" name="no_of_users" id="edit_no_of_users" class="form-control" required>
                            </div>

                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Discount Type') }} <span class="text-danger"> * </span></label>
                                <select name="discount_type" class="form-control" id="edit_discount_type" required>
                                    <option value="percentage">{{ __('Percentage') }}</option>
                                    <option value="amount">{{ __('Fixed') }}</option>
                                </select>
                            </div>

                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Discount') }} <span class="text-danger"> * </span></label>
                                <input type="number" name="discount" id="edit_discount" class="form-control" min="1" max="999999999" step="1" required>
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
    </div> @endsection

@section('script')
    <script>
        // Handle All/Trashed tab switching
        let showDeleted = 0;
        $('.table-list-type').on('click', function(e){
            e.preventDefault();
            $('.table-list-type').removeClass('active');
            $(this).addClass('active');
            showDeleted = $(this).data('id') === 1 ? 1 : 0;

            // Toggle status column visibility
            toggleStatusColumn();

            $('#table_list').bootstrapTable('refresh');
        });

        // Hide status column when viewing trashed items
        $(document).ready(function() {
            // Listen for table refresh/load events
            $('#table_list').on('load-success.bs.table', function() {
                toggleStatusColumn();
            });
        });

        function toggleStatusColumn() {
            const isTrashed = $('.table-list-type.active').data('id') == 1;
            const $table = $('#table_list');

            if (isTrashed) {
                // Hide status column when viewing trashed items
                $table.bootstrapTable('hideColumn', 'status');
            } else {
                // Show status column when viewing all items
                $table.bootstrapTable('showColumn', 'status');
            }
        }

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

        // Attach filters to table query params
        function queryParams(params) {
            params.show_deleted = showDeleted;
            return params;
        }
    </script>
    <script>
    // Custom status formatter for promo codes - shows disabled if expired
    function promoCodeStatusFormatter(value, row, index) {
        // Handle null, undefined, or missing values
        if (value === null || value === undefined || value === '') {
            value = 0;
        }
        // Convert to number if string
        if (typeof value === 'string') {
            value = value === '1' || value === 'true' ? 1 : 0;
        }

        // Check if promo code is expired
        let isExpired = false;
        if (row.end_date) {
            try {
                const endDate = new Date(row.end_date);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                endDate.setHours(0, 0, 0, 0);
                isExpired = endDate < today;
            } catch (e) {
                // If date parsing fails, check is_expired flag from server
                isExpired = row.is_expired === true || row.is_expired === 1;
            }
        } else if (row.is_expired !== undefined) {
            isExpired = row.is_expired === true || row.is_expired === 1;
        }

        // If expired, force status to 0 (disabled)
        if (isExpired) {
            value = 0;
        }

        // Check if status is active
        let checked = (value == 1 || value === true || value === '1' || value === 'true') ? 'checked' : '';
        // Ensure row.id exists
        const rowId = row && row.id ? row.id : (index !== undefined ? 'status_' + index : 'status_unknown');

        // Disable checkbox if expired
        const disabledAttr = isExpired ? 'disabled' : '';
        const disabledClass = isExpired ? 'opacity-50' : '';

        return `
            <div class="custom-control custom-switch custom-switch-2 ${disabledClass}">
                <input type="checkbox" class="custom-control-input update-status" id="${rowId}" ${checked} ${disabledAttr}>
                <label class="custom-control-label" for="${rowId}">&nbsp;</label>
            </div>
            ${isExpired ? '<small class="text-danger d-block mt-1">Expired</small>' : ''}
        `;
    }

    // Define the event handler for Bootstrap Table before document ready
    window.promoCodeAction = {
        'click .edit-data': function (e, value, row) {

            // Clear all previous validation errors and reset form state (using common function)
            if (typeof window.clearEditFormValidationErrors === 'function') {
                window.clearEditFormValidationErrors($('#promoCodeEditForm'));
            }

            // Debug: Log row data to console
            console.log('Row data:', row);
            console.log('Start date:', row.start_date, typeof row.start_date);
            console.log('End date:', row.end_date, typeof row.end_date);

            // Populate all form fields with row data
            $('#edit_promo_code_id').val(row.id);
            $('#edit_promo_code').val(row.promo_code);
            $('#edit_message').val(row.message);

            // Format dates for HTML date input (YYYY-MM-DD)
            let startDate = '';
            let endDate = '';

            // Handle start_date
            if (row.start_date) {
                if (typeof row.start_date === 'string') {
                    // If already in YYYY-MM-DD format, use directly
                    if (row.start_date.match(/^\d{4}-\d{2}-\d{2}$/)) {
                        startDate = row.start_date;
                    } else {
                        // Try to parse various date formats
                        const dateStr = row.start_date.split(' ')[0]; // Get date part if datetime
                        const date = new Date(dateStr);
                        if (!isNaN(date.getTime())) {
                            startDate = date.toISOString().split('T')[0];
                        } else {
                            // Try direct format if it's already close
                            startDate = dateStr;
                        }
                    }
                } else {
                    const date = new Date(row.start_date);
                    if (!isNaN(date.getTime())) {
                        startDate = date.toISOString().split('T')[0];
                    }
                }
            }

            // Handle end_date - same logic
            if (row.end_date && row.end_date !== '' && row.end_date !== null && row.end_date !== undefined) {
                if (typeof row.end_date === 'string') {
                    // If already in YYYY-MM-DD format, use directly
                    if (row.end_date.match(/^\d{4}-\d{2}-\d{2}$/)) {
                        endDate = row.end_date;
                    } else if (row.end_date.trim() !== '') {
                        // Try to parse various date formats
                        const dateStr = row.end_date.split(' ')[0]; // Get date part if datetime
                        const date = new Date(dateStr);
                        if (!isNaN(date.getTime())) {
                            endDate = date.toISOString().split('T')[0];
                        } else {
                            // Try direct format if it's already close
                            endDate = dateStr;
                        }
                    }
                } else {
                    const date = new Date(row.end_date);
                    if (!isNaN(date.getTime())) {
                        endDate = date.toISOString().split('T')[0];
                    }
                }
            }

            console.log('Formatted start date:', startDate);
            console.log('Formatted end date:', endDate);
            console.log('Setting end_date value:', endDate);

            $('#edit_start_date').val(startDate);
            $('#edit_end_date').val(endDate);

            // Force update the end_date field value
            if (endDate) {
                $('#edit_end_date').val(endDate).trigger('change');
            }
            $('#edit_no_of_users').val(row.no_of_users);
            $('#edit_discount_type').val(row.discount_type);

            // Handle discount value - preserve decimal precision
            let discountValue = row.discount;
            // If it's a number, parse it but preserve decimal places
            if (typeof discountValue === 'string') {
                discountValue = parseFloat(discountValue);
            } else if (typeof discountValue === 'number') {
                discountValue = discountValue;
            } else {
                discountValue = parseFloat(discountValue) || 0;
            }

            if (isNaN(discountValue) || discountValue < 0) {
                discountValue = 0;
            }

            // If percentage, max is 100; if amount, max is 999999999
            if (row.discount_type === 'percentage' && discountValue > 100) {
                discountValue = 100;
            } else if (row.discount_type === 'amount' && discountValue > 999999999) {
                discountValue = 999999999;
            }

            // Preserve decimal precision - convert to string to avoid rounding
            // Check if original value had decimals
            const originalDiscount = String(row.discount || '');
            if (originalDiscount.includes('.')) {
                // Preserve decimal places (up to 2 for currency)
                discountValue = parseFloat(discountValue.toFixed(2));
            }

            $('#edit_discount').val(discountValue);

            // Set discount max based on type
            if (row.discount_type === 'percentage') {
                $('#edit_discount').attr('max', '100');
            } else {
                $('#edit_discount').attr('max', '999999999');
            }
            $('#edit_discount').attr('min', '1');

            // Set form action URL
            $('#promoCodeEditForm').attr('action', '{{ route("promo-codes.update", ":id") }}'.replace(':id', row.id));

            // Apply end_date validation after form is populated
            setTimeout(function() {
                const today = new Date().toISOString().split('T')[0];
                const startDate = $('#edit_start_date').val();
                const currentEndDate = $('#edit_end_date').val();

                // Set min to the later of start_date or today
                const minDate = startDate && startDate > today ? startDate : today;
                $('#edit_end_date').attr('min', minDate);

                // Only clear end_date if it's in the past AND not already set from row data
                // Don't clear if we just set it from the row data
                if (currentEndDate && currentEndDate < minDate && !endDate) {
                    $('#edit_end_date').val('');
                } else if (endDate && currentEndDate !== endDate) {
                    // Ensure end_date is set if we have a valid value
                    $('#edit_end_date').val(endDate);
                }
            }, 100);

        }
    };

    $(document).ready(function () {
        // Set discount max based on discount type
        $('#discount_type').on('change', function () {
            const discountType = $(this).val();
            const $discountInput = $('input[name="discount"]');

            if (discountType === 'percentage') {
                $discountInput.attr('max', '100');
            } else {
                $discountInput.attr('max', '999999999');
            }
            $discountInput.attr('min', '1');
        }).trigger('change');

        // Prevent past dates for start_date
        const today = new Date().toISOString().split('T')[0];
        $('#start_date').attr('min', today);

        // Ensure end_date is not earlier than start_date
        $('#start_date').on('change', function () {
            $('#end_date').attr('min', $(this).val());
        });

        if ($('#start_date').val()) {
            $('#end_date').attr('min', $('#start_date').val());
        }
    });
    // Set discount max based on discount type in edit form
    $('#edit_discount_type').on('change', function () {
        const discountType = $(this).val();
        const $discountInput = $('#edit_discount');

        if (discountType === 'percentage') {
            $discountInput.attr('max', '100');
        } else {
            $discountInput.attr('max', '999999999');
        }
        $discountInput.attr('min', '1');
    });

    // Prevent past start_date
    const today = new Date().toISOString().split('T')[0];
    $('#edit_start_date').attr('min', today);

    // Prevent past end_date - set minimum to today or start_date (whichever is later)
    $('#edit_end_date').attr('min', today);

    // Ensure end_date >= start_date and not in the past
    $('#edit_start_date').on('change', function () {
        const startDate = $(this).val();
        const todayDate = new Date().toISOString().split('T')[0];
        // Set min to the later of start_date or today
        const minDate = startDate > todayDate ? startDate : todayDate;
        $('#edit_end_date').attr('min', minDate);

        // If current end_date is before the new minimum, clear it
        if ($('#edit_end_date').val() && $('#edit_end_date').val() < minDate) {
            $('#edit_end_date').val('');
        }
    });

    // Also check on page load if start_date is already set
    if ($('#edit_start_date').val()) {
        const startDate = $('#edit_start_date').val();
        const todayDate = new Date().toISOString().split('T')[0];
        const minDate = startDate > todayDate ? startDate : todayDate;
        $('#edit_end_date').attr('min', minDate);
    }

    // Clear validation errors when modal is closed (using common function)
    // Note: This is also handled automatically by common.js, but keeping it here for specific modal
    $('#promoCodeEditModal').on('hidden.bs.modal', function () {
        if (typeof window.clearEditFormValidationErrors === 'function') {
            window.clearEditFormValidationErrors($('#promoCodeEditForm'));
        }
    });



</script>
@endsection

@section('style')
    <style>
        #table_list th[data-field="status_export"],
        #table_list td[data-field="status_export"] {
            display: none;
        }
    </style>
@endsection

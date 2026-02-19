@extends('layouts.app')

@section('title')
    {{ __('Manage Taxes') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
    </div>
@endsection

@section('main')
    <div class="content-wrapper">

        <!-- Create Form -->
        @can('taxes-create')
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('Create Tax') }}
                        </h4>
                        <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('taxes.store') }}" data-parsley-validate data-success-function="taxFormSuccess"> @csrf <div class="row">
                                <div class="form-group col-sm-12 col-md-4">
                                    <label>{{ __('Name') }} <span class="text-danger"> * </span></label>
                                    <input type="text" name="name" placeholder="{{ __('Tax name, e.g. GST') }}" class="form-control" required>
                                </div>
                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Percentage') }} (%) <span class="text-danger"> * </span></label>
                                    <input type="number" step="0.01" min="1" max="99.99" name="percentage" placeholder="e.g. 2.00" class="form-control" required>
                                </div>
                                <div class="form-group col-sm-12 col-md-2">
                                    <div class="control-label">{{ __('Default Tax') }}</div>
                                    <div class="custom-switches-stacked mt-2">
                                        <label class="custom-switch">
                                            <input type="hidden" name="is_default" value="0">
                                            <input type="checkbox" name="is_default" value="1" class="custom-switch-input" id="is-default-tax" {{ isset($hasDefaultTax) && $hasDefaultTax ? 'disabled' : '' }}>
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description">{{ __('Yes') }}</span>
                                        </label>
                                    </div>
                                    <small class="form-text text-muted" id="default-tax-help-text">
                                        @if(isset($hasDefaultTax) && $hasDefaultTax)
                                            <span class="text-warning">{{ __('A default tax already exists. Edit the existing default tax to modify it.') }}</span>
                                        @else
                                            {{ __('Use as default tax when user country is not available') }}
                                        @endif
                                    </small>
                                </div>
                                <div class="form-group col-sm-12 col-md-3" id="country-field-wrapper">
                                    <label>{{ __('Country') }} <span class="text-danger" >*</span></label>
                                    <select name="country_code" id="country_code" class="form-control select2" data-placeholder="{{ __('Select Country') }}" required>
                                        <option value="">{{ __('Select Country') }}</option>
                                        @if(!empty($countries))
                                            @foreach($countries as $code => $name)
                                                <option value="{{ $code }}">{{ $name }} ({{ $code }})</option>
                                            @endforeach
                                        @endif
                                    </select>

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
                            {{ __('List Taxes') }}
                        </h4>
                        <table aria-describedby="mydesc" class="table reorder-table-row" id="table_list"
                            data-table="taxes" data-toggle="table" data-status-column="is_active"
                            data-url="{{ route('taxes.show', 0) }}" data-click-to-select="true"
                            data-side-pagination="server" data-pagination="true"
                            data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#toolbar"
                            data-show-columns="true" data-show-refresh="true" data-trim-on-search="false"
                            data-mobile-responsive="true" data-use-row-attr-func="true"
                            data-maintain-selected="true" data-export-data-type="all"
                            data-export-options='{ "fileName": "{{ __('taxes') }}-<?= date('d-m-y') ?>","ignoreColumn":["operate","is_active"]}'
                            data-show-export="true" data-query-params="queryParams">
                            <thead>
                                <tr>
                                    <th data-field="id" data-visible="false" data-escape="true">{{ __('id') }}</th>
                                    <th data-field="no" data-escape="true">{{ __('no.') }}</th>
                                    <th data-field="name" data-escape="true">{{ __('Name') }}</th>
                                    <th data-field="percentage" data-escape="true">{{ __('Percentage %') }}</th>
                                    <th data-field="country_code" data-formatter="countryFormatter" data-escape="true">{{ __('Country') }}</th>
                                    <th data-field="is_active" data-formatter="statusFormatter" data-export="false">{{ __('Status') }}</th>
                                    <th data-field="status_export" data-visible="true" data-export="true" class="d-none">{{ __('Status (Export)') }}</th>
                                    <th data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="taxAction" data-escape="false">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div class="modal fade" id="taxEditModal" tabindex="-1" role="dialog"
            aria-labelledby="taxEditModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-md" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="taxEditModalLabel">{{ __('Edit Tax') }}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                            <span aria-hidden="true" style="font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff;">&times;</span>
                        </button>
                    </div>
                    <form class="pt-3 mt-6 edit-form" method="POST" data-parsley-validate id="taxEditForm"> @csrf
                        @method('PUT')
        <div class="modal-body">
                            <input type="hidden" name="id" id="edit_tax_id">
                            <div class="form-group">
                                <label>{{ __('Name') }} <span class="text-danger"> * </span></label>
                                <input type="text" name="name" id="edit_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>{{ __('Percentage') }} (%)</label>
                                <input type="number" name="percentage" step="0.01" min="1" max="99.99" id="edit_percentage" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <div class="control-label">{{ __('Default Tax') }}</div>
                                <div class="custom-switches-stacked mt-2">
                                    <label class="custom-switch">
                                        <input type="hidden" name="is_default" value="0">
                                        <input type="checkbox" name="is_default" value="1" class="custom-switch-input" id="edit_is_default">
                                        <span class="custom-switch-indicator"></span>
                                        <span class="custom-switch-description">{{ __('Yes') }}</span>
                                    </label>
                                </div>
                                <small class="form-text text-muted" id="edit-default-tax-help-text">{{ __('Use as default tax when user country is not available') }}</small>
                            </div>
                            <div class="form-group" id="edit-country-field-wrapper">
                                <label>{{ __('Country') }} <span class="text-danger" id="edit-country-required">*</span></label>
                                <select name="country_code" id="edit_country_code" class="form-control select2" data-placeholder="{{ __('Select Country') }}" required>
                                    <option value="">{{ __('Select Country') }}</option>
                                    @if(!empty($countries))
                                        @foreach($countries as $code => $name)
                                            <option value="{{ $code }}">{{ $name }} ({{ $code }})</option>
                                        @endforeach
                                    @endif
                                </select>
                                <small class="form-text text-muted" id="edit-country-help-text">{{ __('Select country for this tax') }}</small>
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

@section('style')
    <style>
        /* Ensure Select2 dropdown appears above modal backdrop */
        .select2-container--open {
            z-index: 9999 !important;
        }

        /* Modal z-index fix for Select2 */
        #taxEditModal {
            z-index: 1050;
        }

        #taxEditModal .select2-container {
            z-index: 9999;
        }
    </style>
@endsection

@section('script')
    <script>
        console.log('=== TAXES PAGE SCRIPT LOADING ===');

        // Test: Listen to ALL bootstrap table events to see what's happening
        $(document).on('all.bs.table', '#table_list', function(e) {
            console.log('Bootstrap Table event:', e.type);
        });

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
            return params;
        }

        // Function to clean row data for export
        function cleanRowForExport(row) {
            console.log('cleanRowForExport called with:', row);

            if (!row) return row;

            // Create a copy to avoid modifying original
            const cleanedRow = Object.assign({}, row);

            // Clean is_active status (convert HTML checkbox to Active/Deactive)
            // Always ensure is_active field exists in export
            let isActiveValue = null;

            // First, try to get raw value from row
            if (cleanedRow.is_active !== undefined && cleanedRow.is_active !== null) {
                isActiveValue = cleanedRow.is_active;
            }

            console.log('cleanRowForExport - isActiveValue:', isActiveValue, 'type:', typeof isActiveValue);

            // If is_active is HTML (from formatter), extract the checked state
            if (typeof isActiveValue === 'string' && isActiveValue.includes('<input')) {
                const isChecked = isActiveValue.includes('checked');
                cleanedRow.is_active = isChecked ? 'Active' : 'Deactive';
                console.log('cleanRowForExport - HTML checkbox detected, result:', cleanedRow.is_active);
            } else {
                // Handle raw numeric/boolean values
                // Check multiple possible formats
                const isActive = isActiveValue == 1 ||
                                isActiveValue === '1' ||
                                isActiveValue === 'true' ||
                                isActiveValue === true ||
                                isActiveValue === 1 ||
                                (typeof isActiveValue === 'string' && isActiveValue.toLowerCase() === 'active');

                cleanedRow.is_active = isActive ? 'Active' : 'Deactive';
                console.log('cleanRowForExport - raw value processed, result:', cleanedRow.is_active);
            }

            // Always ensure is_active is set (even if it was undefined/null)
            if (!cleanedRow.hasOwnProperty('is_active') || cleanedRow.is_active === undefined || cleanedRow.is_active === null) {
                cleanedRow.is_active = 'Deactive';
                console.log('cleanRowForExport - is_active was missing, set to Deactive');
            }

            // Clean country_code (remove HTML badges)
            if (cleanedRow.country_code && typeof cleanedRow.country_code === 'string') {
                cleanedRow.country_code = cleanedRow.country_code.replace(/<[^>]*>/g, '').trim();
            }

            console.log('cleanRowForExport returning:', cleanedRow);
            return cleanedRow;
        }

        // Export body handler for Bootstrap Table
        window.exportBodyHandler = function(rows) {
            if (rows && Array.isArray(rows)) {
                return rows.map(function(row) {
                    return cleanRowForExport(row);
                });
            }
            return rows;
        };

        // Export formatter for status column (used during CSV/Excel export)
        function statusExportFormatter(value, row, index) {
            console.log('statusExportFormatter called with:', {value: value, row: row, index: index});

            // Get raw is_active value
            let statusValue = value;

            if (row && row.is_active !== undefined) {
                statusValue = row.is_active;
            }

            // Convert to Active/Deactive
            const isActive = statusValue == 1 ||
                            statusValue === '1' ||
                            statusValue === 'true' ||
                            statusValue === true ||
                            statusValue === 1;

            const result = isActive ? 'Active' : 'Deactive';
            console.log('statusExportFormatter returning:', result);
            return result;
        }

        // Override statusFormatter to handle is_active for taxes
        (function() {
            const originalStatusFormatter = window.statusFormatter;
            window.statusFormatter = function(value, row, index) {
                // Use is_active for taxes table
                const statusValue = row.is_active !== undefined ? row.is_active : value;

                // Store raw value for export (preserve original value before formatter replaces it)
                if (row._is_active === undefined) {
                    row._is_active = statusValue;
                }

                // For display, use checkbox
                const checked = (statusValue == 1 || statusValue === true || statusValue === '1') ? 'checked' : '';
                const rowId = row.id || 'is_active_' + index;
                return `
                    <div class="custom-control custom-switch custom-switch-2">
                        <input type="checkbox" class="custom-control-input update-status" id="${rowId}" ${checked}>
                        <label class="custom-control-label" for="${rowId}">&nbsp;</label>
                    </div>
                `;
            };
        })();

        // Attach export event handler BEFORE table initialization
        // Use document-level event delegation to catch all export events
        $(document).on('export.bs.table', '#table_list', function (e, name, args) {
            console.log('=== EXPORT EVENT TRIGGERED (document level) ===');
            console.log('Export type:', name);
            console.log('Args:', args);

            // Get all table data (all pages, not just current page)
            const tableData = $('#table_list').bootstrapTable('getData', {useCurrentPage: false});
            console.log('Table data retrieved:', tableData);

            if (tableData && Array.isArray(tableData)) {
                // Clean all rows - ensure is_active is set to Active/Deactive
                const cleanedData = tableData.map(function(row) {
                    // Use cleanRowForExport to format the value (it handles is_active conversion)
                    console.log('Calling cleanRowForExport for row:', row);
                    const cleanedRow = cleanRowForExport(row);
                    console.log('Result from cleanRowForExport:', cleanedRow);
                    return cleanedRow;
                });

                // Update args with cleaned data
                if (args && args.data) {
                    args.data = cleanedData;
                } else if (Array.isArray(args)) {
                    // Replace args array with cleaned data
                    args.length = 0;
                    cleanedData.forEach(function(row) {
                        args.push(row);
                    });
                } else if (args && args.rows) {
                    args.rows = cleanedData;
                } else if (args) {
                    // If args is an object but doesn't have data/rows, add data
                    args.data = cleanedData;
                }

                console.log('Cleaned data prepared:', cleanedData);
            }

            // Also handle args directly if it's an array (for PDF)
            if (args && Array.isArray(args)) {
                for (let i = 0; i < args.length; i++) {
                    args[i] = cleanRowForExport(args[i]);
                }
            }
            // Handle if args is an object with rows array
            if (args && args.rows && Array.isArray(args.rows)) {
                args.rows = args.rows.map(function(row, index) {
                    return cleanRowForExport(row);
                });
            }

            console.log('=== EXPORT EVENT COMPLETED ===');
            console.log('Final args:', args);
        });

        // Setup status export using common function
        $(document).ready(function() {
            // Use common setupStatusExport function from formatter.js
            setupStatusExport('#table_list', 'is_active');
        });

        // Country formatter for table
        function countryFormatter(value, row) {
            // Check if it's default tax
            if (row.is_default == 1 || row.is_default === true || row.is_default === '1') {
                return '<span class="badge badge-success">' + '{{ __('Default Tax') }}' + '</span>';
            }

            if (!value || value === '') {
                return '<span class="badge badge-secondary">-</span>';
            }

            try {
                // Try to get country name from Intl
                const countryName = new Intl.DisplayNames(['en'], { type: 'region' }).of(value);
                return '<span class="badge badge-primary">' + countryName + ' (' + value + ')' + '</span>';
            } catch (e) {
                return '<span class="badge badge-primary">' + value + '</span>';
            }
        }

            // Initialize select2 for country dropdowns
        $(document).ready(function() {
            // Initialize select2 for create form
            $('#country_code').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: '{{ __('Select Country') }}',
                allowClear: false,
                dropdownParent: $('#country_code').closest('.card-body')
            });

            // Initialize select2 for edit form - will be re-initialized when modal opens
            function initEditCountrySelect2() {
                if ($('#edit_country_code').length && !$('#edit_country_code').hasClass('select2-hidden-accessible')) {
                    $('#edit_country_code').select2({
                        theme: 'bootstrap-5',
                        width: '100%',
                        placeholder: '{{ __('Select Country') }}',
                        allowClear: false,
                        dropdownParent: $('#taxEditModal')
                    });
                }
            }

            // Handle default tax checkbox - show/hide country field
            function toggleCountryField(isDefault) {
                if (isDefault) {
                    $('#country-field-wrapper').hide();
                    $('#country_code').removeAttr('required').val('').trigger('change');
                    $('#country-required').hide();
                    $('#country-help-text').text('{{ __('Default tax - applies when user country is not available') }}');
                } else {
                    $('#country-field-wrapper').show();
                    $('#country_code').attr('required', 'required');
                    $('#country-required').show();
                    $('#country-help-text').text('{{ __('Select country for this tax') }}');
                }
            }

            // Create form - default tax toggle
            $('#is-default-tax').on('change', function() {
                toggleCountryField($(this).is(':checked'));
            });

            // Initialize on page load
            toggleCountryField($('#is-default-tax').is(':checked'));
            
            // Function to check if default tax exists and update UI
            function checkDefaultTaxExists() {
                // Check table data for default tax
                const tableData = $('#table_list').bootstrapTable('getData');
                const hasDefaultTax = tableData.some(row => row.is_default == 1 || row.is_default === true || row.is_default === '1');
                
                const $checkbox = $('#is-default-tax');
                const $helpText = $('#default-tax-help-text');
                
                if (hasDefaultTax) {
                    $checkbox.prop('disabled', true).prop('checked', false);
                    $helpText.html('<span class="text-warning">{{ __('A default tax already exists. Edit the existing default tax to modify it.') }}</span>');
                } else {
                    $checkbox.prop('disabled', false);
                    $helpText.text('{{ __('Use as default tax when user country is not available') }}');
                }
            }
            
            // Check on table load/refresh
            $('#table_list').on('load-success.bs.table', function() {
                checkDefaultTaxExists();
            });
            
            // Initial check
            $(document).ready(function() {
                setTimeout(checkDefaultTaxExists, 500);
            });
            
            // Custom success function for tax form - reset country field state after form reset
            window.taxFormSuccess = function(response) {
                // After form reset, ensure country field state matches checkbox state
                setTimeout(function() {
                    toggleCountryField($('#is-default-tax').is(':checked'));
                    // Clear any validation errors
                    $('#country_code').removeClass('parsley-error');
                    $('#country_code').next('.parsley-errors-list').remove();
                    // Re-check if default tax exists after table refresh
                    setTimeout(checkDefaultTaxExists, 500);
                }, 100);
            };

            // Edit form - default tax toggle
            $('#edit_is_default').on('change', function() {
                const isDefault = $(this).is(':checked');
                if (isDefault) {
                    $('#edit-country-field-wrapper').hide();
                    $('#edit_country_code').removeAttr('required').val('').trigger('change');
                    $('#edit-country-required').hide();
                    $('#edit-country-help-text').text('{{ __('Default tax - applies when user country is not available') }}');
                } else {
                    $('#edit-country-field-wrapper').show();
                    $('#edit_country_code').attr('required', 'required');
                    $('#edit-country-required').show();
                    $('#edit-country-help-text').text('{{ __('Select country for this tax') }}');
                }
            });

            // Handle edit button click - store row data for modal
            window.taxAction = {
                'click .edit_btn': function (e, value, row, index) {
                    // Store row data to be used when modal is shown
                    currentEditRow = row;
                }
            };

            // Store row data when edit button is clicked
            let currentEditRow = null;

            // Refresh Select2 and populate data when modal is shown
            $('#taxEditModal').on('shown.bs.modal', function() {
                // Re-initialize Select2 for edit form
                if ($('#edit_country_code').hasClass('select2-hidden-accessible')) {
                    $('#edit_country_code').select2('destroy');
                }
                initEditCountrySelect2();

                if (currentEditRow) {
                    const row = currentEditRow;
                    // Set form action URL
                    const updateUrl = '{{ route("taxes.update", ":id") }}'.replace(':id', row.id);
                    $('#taxEditForm').attr('action', updateUrl);

                    // Set form values
                    $('#edit_tax_id').val(row.id || '');
                    $('#edit_name').val(row.name || '');
                    $('#edit_percentage').val(row.percentage || '');

                    // Handle default tax checkbox
                    const isDefault = row.is_default == 1 || row.is_default === true || row.is_default === '1' || row.is_default === 1;

                    // Check if another default tax exists (not the current one)
                    const tableData = $('#table_list').bootstrapTable('getData');
                    const anotherDefaultExists = tableData.some(r =>
                        r.id != row.id && (r.is_default == 1 || r.is_default === true || r.is_default === '1')
                    );

                    if (isDefault) {
                        // Current tax is the default - allow editing (can turn it off)
                        $('#edit_is_default').prop('checked', true).prop('disabled', false);
                        $('#edit-default-tax-help-text').text('{{ __('Use as default tax when user country is not available') }}');
                        $('#edit-country-field-wrapper').hide();
                        $('#edit_country_code').removeAttr('required').val('').trigger('change');
                        $('#edit-country-required').hide();
                        $('#edit-country-help-text').text('{{ __('Default tax - applies when user country is not available') }}');
                    } else {
                        $('#edit_is_default').prop('checked', false);

                        // If another default exists, disable the checkbox
                        if (anotherDefaultExists) {
                            $('#edit_is_default').prop('disabled', true);
                            $('#edit-default-tax-help-text').html('<span class="text-warning">{{ __('A default tax already exists. Edit the existing default tax to modify it.') }}</span>');
                        } else {
                            $('#edit_is_default').prop('disabled', false);
                            $('#edit-default-tax-help-text').text('{{ __('Use as default tax when user country is not available') }}');
                        }

                        $('#edit-country-field-wrapper').show();
                        $('#edit_country_code').attr('required', 'required');
                        $('#edit-country-required').show();
                        $('#edit-country-help-text').text('{{ __('Select country for this tax') }}');

                        // Set country code in select2 - wait for Select2 to be initialized
                        setTimeout(function() {
                            if (row.country_code) {
                                $('#edit_country_code').val(row.country_code).trigger('change');
                            } else {
                                $('#edit_country_code').val('').trigger('change');
                            }
                        }, 100);
                    }
                    currentEditRow = null; // Clear after use
                }
            });
        });
    </script>
@endsection

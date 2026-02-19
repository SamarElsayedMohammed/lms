@extends('layouts.app')

@php
    // Permission flags for use in JavaScript (status toggle display)
    $canEdit = auth()->user()->can('categories-edit');
@endphp

@section('title')
    {{ __('Create Categories') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>

    @can('categories-create')
    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('categories.create') }}">
            + {{ __('Add Category') }}
        </a>
    </div>
    @endcan
@endsection

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        @can('categories-reorder')
                        <div class="text-left col-md-12">
                            <a href="{{ route('categories.order') }}">+ {{ __('Set Order of Categories') }} </a>
                        </div>
                        @endcan
                         {{-- Show Trash Button --}}
                        @can('categories-trash')
                        <div class="mt-4 text-right">
                            <b><a href="#" class="table-list-type active mr-2" data-id="0">{{ __('All') }}</a></b> {{ __('|') }} <a href="#" class="ml-2 table-list-type" data-id="1">{{ __('Trashed') }}</a>
                        </div>
                        @endcan
                    </div>
                    <div id="toolbar"></div>
                    <div class="table-responsive">
                        <table class="table table-border" id="table_list" data-toggle="table" 
                            data-url="{{ route('categories.show', 0) }}" data-pagination="true"
                            data-side-pagination="server" data-search="true" data-toolbar="#toolbar"
                            data-page-list="[5, 10, 20, 50, 100]" data-show-columns="true" data-show-refresh="true"
                            data-sort-name="id" data-sort-order="desc" data-show-columns="true"
                            data-status-column="status" data-query-params="categoriesQueryParams" data-mobile-responsive="true"
                            data-table="categories" data-show-export="true"
                            data-export-data-type="all"
                            data-export-options='{"fileName": "category-list","ignoreColumn": ["operate", "image", "status", "subcategories_count"]}'
                            data-export-types='["json", "xml", "csv", "txt", "sql", "excel"]'>
                            <thead>
                                <tr>
                                    <th data-field="id" data-align="center" data-sortable="true" data-escape="true">{{ __('ID') }}</th>
                                    <th data-field="name" data-sortable="true" data-formatter="categoryNameFormatter" data-escape="false">{{ __('Name') }}</th>
                                    <th data-field="image" data-align="center" data-formatter="imageFormatter" data-escape="false">{{ __('Image') }}</th>
                                    <th data-field="subcategories_count" data-align="center" data-formatter="subCategoryFormatter" data-export="false" data-escape="false">{{ __('Subcategories') }}</th>
                                    <th data-field="subcategories_count_export" data-visible="true" data-export="true" class="d-none">{{ __('Subcategories (Export)') }}</th>
                                    {{-- <th scope="col" data-field="custom_fields_count" data-align="center" data-sortable="false" data-formatter="customFieldFormatter">{{ __('Custom Fields') }}</th> --}}
                                    <th data-field="status" data-align="center" data-formatter="statusFormatter" data-export="false" data-escape="false">{{ __('Active') }}</th>
                                    <th data-field="status_export" data-visible="true" data-export="true" class="d-none">{{ __('Status (Export)') }}</th>
                                    <th data-field="operate" data-align="" data-formatter="actionColumnFormatter" data-escape="false" data-export="false">{{ __('Action') }}</th>
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
        // Permission flag for JavaScript (controls status toggle display)
        window.categoryPermissions = {
            canEdit: {{ $canEdit ? 'true' : 'false' }}
        };

        // Override statusFormatter BEFORE Bootstrap Table initializes
        // This ensures the formatter is available when the table loads
        (function() {
            // Store original formatter if it exists
            const originalStatusFormatter = window.statusFormatter;
            
            // Override statusFormatter to ensure it always returns HTML
            window.statusFormatter = function(value, row, index) {
                // Check if user has edit permission
                if (window.categoryPermissions && !window.categoryPermissions.canEdit) {
                    // Show read-only status badge instead of toggle
                    const statusValue = (row && row.status !== undefined) ? row.status : value;
                    const isActive = statusValue == 1 || statusValue === '1' || statusValue === true;
                    return isActive
                        ? '<span class="badge badge-success">{{ __("Active") }}</span>'
                        : '<span class="badge badge-secondary">{{ __("Inactive") }}</span>';
                }

                // Always get value from row.status if available (more reliable)
                if (row && row.status !== undefined && row.status !== null) {
                    value = row.status;
                }

                // If value is still null/undefined/empty, default to 0
                if (value === null || value === undefined || value === '') {
                    value = 0;
                }

                // Convert to number if string
                if (typeof value === 'string') {
                    value = (value === '1' || value === 'true') ? 1 : 0;
                }

                // Convert boolean to number
                if (typeof value === 'boolean') {
                    value = value ? 1 : 0;
                }

                // Ensure value is always a number (0 or 1)
                value = parseInt(value) || 0;

                // Ensure row.id exists
                const rowId = (row && row.id) ? row.id : (index !== undefined ? 'status_' + index : 'status_unknown');

                // Determine checked state
                const checked = (value == 1) ? 'checked' : '';

                // Return HTML
                return `
                    <div class="custom-control custom-switch custom-switch-2">
                        <input type="checkbox" class="custom-control-input update-status" id="${rowId}" ${checked}>
                        <label class="custom-control-label" for="${rowId}">&nbsp;</label>
                    </div>
                `;
            };
        })();
        
        // Function to clean row data for export
        function cleanRowForExport(row) {
            if (!row) return row;
            // Clean category name (remove button HTML)
            if (row.name) {
                row.name = row.name.replace(/<[^>]*>/g, '').trim();
            }
            // Clean image field (just show URL or "-")
            if (row.image) {
                const imgMatch = row.image.match(/href=['"]([^'"]+)['"]/);
                row.image = imgMatch ? imgMatch[1] : (row.image.includes('http') ? row.image : '-');
            }
            // Clean subcategories count
            if (row.subcategories_count !== undefined) {
                row.subcategories_count = row.subcategories_count || 0;
            }
            // Clean status (convert HTML checkbox to Yes/No)
            // Always ensure status field exists in export
            if (row.status !== undefined && row.status !== null) {
                // Check if status is HTML (from formatter) or raw value
                if (typeof row.status === 'string' && row.status.includes('<input')) {
                    // Extract checked state from HTML checkbox
                    const isChecked = row.status.includes('checked');
                    row.status = isChecked ? 'Yes' : 'No';
                } else {
                    // Handle raw numeric/boolean values
                    const statusValue = row.status == 1 || row.status === '1' || row.status === 'true' || row.status === true;
                    row.status = statusValue ? 'Yes' : 'No';
                }
            } else {
                // Default to 'No' if status is undefined or null
                row.status = 'No';
            }
            return row;
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
        
        $(document).ready(function() {
            // Setup status export using common function
            setupStatusExport('#table_list', 'status');
            
            // Ensure status column is properly rendered after table loads
            $('#table_list').on('load-success.bs.table', function (e, data) {
                // Hide Action column if no rows have any actions (all operate fields are empty)
                if (data && data.rows) {
                    const hasAnyActions = data.rows.some(row => row.operate && row.operate.trim() !== '');
                    if (!hasAnyActions) {
                        $('#table_list').bootstrapTable('hideColumn', 'operate');
                    } else {
                        $('#table_list').bootstrapTable('showColumn', 'operate');
                    }
                }

                // Ensure all status cells have content
                setTimeout(function() {
                    $('#table_list tbody tr').each(function(index) {
                        const $row = $(this);
                        const $statusCell = $row.find('td').eq(4); // Status is 5th column (0-indexed: 4)
                        
                        // If status cell is empty, get data and render it
                        if ($statusCell.length && ($statusCell.html().trim() === '' || $statusCell.html().trim() === '&nbsp;')) {
                            const rowData = $row.data('index') !== undefined ? 
                                $('#table_list').bootstrapTable('getData')[$row.data('index')] : null;
                            
                            if (rowData && rowData.status !== undefined) {
                                const statusValue = rowData.status;
                                const checked = (statusValue == 1 || statusValue === true || statusValue === '1') ? 'checked' : '';
                                const rowId = rowData.id || 'status_' + index;
                                const statusHtml = `
                                    <div class="custom-control custom-switch custom-switch-2">
                                        <input type="checkbox" class="custom-control-input update-status" id="${rowId}" ${checked}>
                                        <label class="custom-control-label" for="${rowId}">&nbsp;</label>
                                    </div>
                                `;
                                $statusCell.html(statusHtml);
                            }
                        }
                    });
                }, 100);
            });
            
            // Handle All/Trashed tab switching
            $('.table-list-type').on('click', function(e){
                e.preventDefault();
                $('.table-list-type').removeClass('active');
                $(this).addClass('active');
                
                const isTrashed = $(this).data('id') === 1;
                
                // Hide/Show Active column based on tab
                if (isTrashed) {
                    $('#table_list').bootstrapTable('hideColumn', 'status');
                } else {
                    $('#table_list').bootstrapTable('showColumn', 'status');
                }
                
                // Refresh table
                $('#table_list').bootstrapTable('refresh');
            });
            
            // Export formatters to clean HTML for CSV/Excel/PDF export and fetch all data
            $('#table_list').on('export.bs.table', function (e, name, args) {
                const $table = $('#table_list');
                const tableInstance = $table.data('bootstrap.table');
                
                // For server-side pagination, fetch all data
                if (tableInstance && tableInstance.options.sidePagination === 'server') {
                    e.preventDefault(); // Prevent default export
                    e.stopPropagation();
                    
                    // Get current query params
                    const queryParams = tableInstance.options.queryParams || function() { return {}; };
                    const params = queryParams({});
                    
                    // Set limit to a very high number to get all records
                    params.limit = 999999;
                    params.offset = 0;
                    params.search = params.search || '';
                    params.show_deleted = params.show_deleted || 0;
                    
                    // Fetch all data from server synchronously using async/await pattern
                    $.ajax({
                        url: tableInstance.options.url,
                        type: 'GET',
                        data: params,
                        async: false, // Make synchronous to ensure data is fetched before export
                        success: function(response) {
                            if (response && response.rows && Array.isArray(response.rows)) {
                                // Clean the data for export
                                const cleanedData = response.rows.map(function(row) {
                                    // Ensure status_export is present
                                    if (!row.status_export && row.status !== undefined) {
                                        row.status_export = (row.status == 1 || row.status === 1 || row.status === '1' || row.status === true) ? 'Active' : 'Deactive';
                                    }
                                    // Ensure subcategories_count_export is present
                                    if (!row.subcategories_count_export && row.subcategories_count !== undefined) {
                                        row.subcategories_count_export = parseInt(row.subcategories_count) || 0;
                                    }
                                    return cleanRowForExport(row);
                                });
                                
                                // Update args with all data
                                if (args && args.data) {
                                    args.data = cleanedData;
                                } else if (Array.isArray(args)) {
                                    args.length = 0;
                                    cleanedData.forEach(function(row) {
                                        args.push(row);
                                    });
                                } else if (args && args.rows) {
                                    args.rows = cleanedData;
                                } else if (args) {
                                    args.data = cleanedData;
                                }
                                
                                // Now trigger the export with updated args
                                setTimeout(function() {
                                    $table.bootstrapTable('exportTable', {
                                        type: name,
                                        exportOptions: {
                                            fileName: 'category-list',
                                            ignoreColumn: ['operate', 'image', 'status', 'subcategories_count']
                                        }
                                    });
                                }, 100);
                            }
                        },
                        error: function() {
                            console.error('Failed to fetch all data for export');
                            // Fallback to default export
                            $table.bootstrapTable('exportTable', {
                                type: name
                            });
                        }
                    });
                } else {
                    // For client-side pagination, just clean the data
                    if (args && args.data && Array.isArray(args.data)) {
                        args.data = args.data.map(function(row) {
                            return cleanRowForExport(row);
                        });
                    }
                    if (args && Array.isArray(args)) {
                        for (let i = 0; i < args.length; i++) {
                            args[i] = cleanRowForExport(args[i]);
                        }
                    }
                    if (args && args.rows && Array.isArray(args.rows)) {
                        args.rows = args.rows.map(function(row) {
                            return cleanRowForExport(row);
                        });
                    }
                }
            });
            
            // Also handle exported event to ensure data is cleaned
            $('#table_list').on('exported.bs.table', function (e, name, args) {
                // Data should already be cleaned, but ensure it is
            });
            
            // Override table's getData method to ensure status is always included
            // Wait for table to be initialized
            setTimeout(function() {
                const table = $('#table_list').data('bootstrap.table');
                if (table && table.getData) {
                    const originalGetData = table.getData;
                    table.getData = function(useCurrentPage) {
                        const data = originalGetData.call(this, useCurrentPage);
                        if (data && Array.isArray(data)) {
                            return data.map(function(row) {
                                // Ensure status is always present
                                if (row.status === undefined || row.status === null) {
                                    row.status = 0;
                                }
                                return row;
                            });
                        }
                        return data;
                    };
                }
            }, 500);
        });
    </script>
@endsection

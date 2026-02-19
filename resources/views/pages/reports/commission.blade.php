@extends('layouts.app')

@section('title')
    {{ __('Commission Reports') }}
@endsection
@push('style')
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="{{ asset('library/bootstrap-daterangepicker/daterangepicker.css') }}">
    <link rel="stylesheet" href="{{ asset('library/select2/dist/css/select2.min.css') }}">
    <link rel="stylesheet" href="{{ asset('library/datatables/media/css/jquery.dataTables.min.css') }}">
@endpush

@section('main')
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-handshake"></i> {{ __('Commission Reports') }} </h1>
            <div class="section-header-button">
                @can('reports-commission-export')
                <button class="btn btn-primary" onclick="exportReport('pdf')">
                    <i class="fas fa-file-pdf"></i> {{ __('Export PDF') }} </button>
                <button class="btn btn-success ml-2" onclick="exportReport('excel')">
                    <i class="fas fa-file-excel"></i> {{ __('Export Excel') }} </button>
                @endcan
                <button class="btn btn-info ml-2" onclick="refreshData()">
                    <i class="fas fa-sync-alt"></i> {{ __('Refresh') }} </button>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <h5><i class="fas fa-filter"></i> {{ __('Filters') }} </h5>
            <form id="filterForm">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label> {{ __('Date Range') }} </label>
                            <input type="text" class="form-control" id="dateRange" name="dateRange" readonly>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label> {{ __('Course') }} </label>
                            <select class="form-control select2" id="courseFilter" name="course_id">
                                <option value=""> {{ __('All Courses') }} </option>
                            </select>
                        </div>
                    </div>
                    @if($shouldShowInstructorFilters ?? true)
                    <div class="col-md-3">
                        <div class="form-group">
                            <label> {{ __('Instructor') }} </label>
                            <select class="form-control select2" id="instructorFilter" name="instructor_id">
                                <option value=""> {{ __('All Instructors') }} </option>
                            </select>
                        </div>
                    </div>
                    @endif
                    <div class="col-md-3">
                        <div class="form-group">
                            <label> {{ __('Status') }} </label>
                            <select class="form-control" id="statusFilter" name="status">
                                <option value=""> {{ __('All Status') }} </option>
                                <option value="pending"> {{ __('Pending') }} </option>
                                <option value="paid"> {{ __('Paid') }} </option>
                                <option value="cancelled"> {{ __('Cancelled') }} </option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    @if($shouldShowInstructorFilters ?? true)
                    <div class="col-md-3">
                        <div class="form-group">
                            <label> {{ __('Instructor Type') }} </label>
                            <select class="form-control" id="instructorTypeFilter" name="instructor_type">
                                <option value=""> {{ __('All Types') }} </option>
                                <option value="individual"> {{ __('Individual') }} </option>
                                <option value="team"> {{ __('Team') }} </option>
                            </select>
                        </div>
                    </div>
                    @endif
                    <div class="col-md-3">
                        <div class="form-group">
                            <label> &nbsp; </label>
                            <button type="button" class="btn btn-primary btn-block" onclick="applyFilters()">
                                <i class="fas fa-search"></i> {{ __('Apply Filters') }} </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Loading State -->
        <div id="loading-state" class="text-center py-4" style="display: none;">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only"> {{ __('Loading...') }} </span>
            </div>
            <p class="mt-2 text-muted"> {{ __('Loading commission data...') }} </p>
        </div>

        <!-- Summary Cards -->
        <div id="summary-section">
            <div class="row">
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card commission-card">
                        <div class="card-wrap">
                            <div class="card-icon bg-primary">
                                <i class="fas fa-handshake"></i>
                            </div>
                            <div class="card-header">
                                <h4> {{ __('Total Commissions') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="totalCommissions"> {{ __('0') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-wrap">
                            <div class="card-icon bg-success">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                            <div class="card-header">
                                <h4> {{ __('Admin Commission') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="totalAdminCommission">{{ __('0') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-wrap">
                            <div class="card-icon bg-warning">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <div class="card-header">
                                <h4> {{ __('Instructor Commission') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="totalInstructorCommission">{{ __('0') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-wrap">
                            <div class="card-icon bg-info">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="card-header">
                                <h4> {{ __('Paid Commissions') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="paidCommissions"> {{ __('0') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Table -->
        <div id="table-section" class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-table"></i> {{ __('Commission Data') }} </h4>
                        <div class="card-header-action">
                            <select class="form-control" id="perPage" onchange="loadTableData()">
                                <option value="15"> {{ __('15 per page') }} </option>
                                <option value="25"> {{ __('25 per page') }} </option>
                                <option value="50"> {{ __('50 per page') }} </option>
                                <option value="100"> {{ __('100 per page') }} </option>
                            </select>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" id="commissionTable">
                                <thead>
                                    <tr>
                                        <th> {{ __('Date') }} </th>
                                        <th> {{ __('Instructor') }} </th>
                                        <th> {{ __('Course') }} </th>
                                        <th> {{ __('Type') }} </th>
                                        <th> {{ __('Course Price') }} </th>
                                        <th> {{ __('Admin Commission') }} </th>
                                        <th> {{ __('Instructor Commission') }} </th>
                                        <th> {{ __('Status') }} </th>
                                        <th> {{ __('Paid At') }} </th>
                                    </tr>
                                </thead>
                                <tbody id="commissionTableBody">
                                    <!-- Data will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div id="pagination-info"></div>
                            <nav>
                                <ul class="pagination" id="pagination"></ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Instructors Section -->
        <div id="top-instructors-section" class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-trophy"></i> {{ __('Top Earning Instructors') }} </h4>
                    </div>
                    <div class="card-body">
                        <div id="topInstructorsList">
                            <!-- Top instructors will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-pie"></i> {{ __('Commission by Course') }} </h4>
                    </div>
                    <div class="card-body">
                        <div id="commissionByCourseList">
                            <!-- Commission by course will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
<!-- JS Libraries -->
    <script src="{{ asset('library/moment/min/moment.min.js') }}"></script>
    <script src="{{ asset('library/bootstrap-daterangepicker/daterangepicker.js') }}"></script>
    <script src="{{ asset('library/select2/dist/js/select2.full.min.js') }}"></script>
    <script src="{{ asset('library/chart.js/dist/Chart.min.js') }}"></script>
    <script src="{{ asset('library/datatables/media/js/jquery.dataTables.min.js') }}"></script>

    <script>
        let currentPage = 1;
        let currentFilters = {};
        const currencySymbol = '{{ $currency_symbol ?? "₹" }}';

        $(document).ready(function() {
            initializePage();
            loadInitialData();
        });

        function initializePage() {
            // Initialize date range picker
            $('#dateRange').daterangepicker({
                startDate: moment().subtract(29, 'days'),
                endDate: moment(),
                locale: {
                    format: 'DD/MM/YYYY'
                },
                ranges: {
                    'Today': [moment(), moment()],
                    'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                    'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                    'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                    'This Month': [moment().startOf('month'), moment().endOf('month')],
                    'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
                }
            });

            // Initialize Select2
            $('.select2').select2({
                placeholder: 'Select an option',
                allowClear: true
            });

            // Load filter options
            loadFilterOptions();
        }

        function loadFilterOptions() {
            $.get('/reports/filters', function(response) {
                if (response.success) {
                    const data = response.data;

                    // Populate courses
                    $('#courseFilter').empty().append('<option value=""> {{ __('All Courses') }} </option>');
                    data.courses.forEach(course => {
                        $('#courseFilter').append(`<option value="${course.id}">${course.title}</option>`);
                    });

                    // Populate instructors
                    $('#instructorFilter').empty().append('<option value=""> {{ __('All Instructors') }} </option>');
                    data.instructors.forEach(instructor => {
                        $('#instructorFilter').append(`<option value="${instructor.id}">${instructor.name}</option>`);
                    });
                }
            });
        }

        function loadInitialData() {
            applyFilters();
        }

        function applyFilters() {
            showLoading(true);

            try {
                // Get filter values
                const dateRange = $('#dateRange').val().split(' - ');
                currentFilters = {
                    date_from: moment(dateRange[0].trim(), 'DD/MM/YYYY').format('YYYY-MM-DD'),
                    date_to: moment(dateRange[1].trim(), 'DD/MM/YYYY').format('YYYY-MM-DD'),
                    course_id: $('#courseFilter').val(),
                    instructor_id: $('#instructorFilter').val(),
                    status: $('#statusFilter').val(),
                    instructor_type: $('#instructorTypeFilter').val()
                };

                // Remove empty values
                Object.keys(currentFilters).forEach(key => {
                    if (!currentFilters[key]) {
                        delete currentFilters[key];
                    }
                });

                loadCommissionData();
            } catch (error) {
                console.error('Error in applyFilters:', error);
                showLoading(false);
                showError('Error processing filters: ' + error.message);
            }
        }

        function loadCommissionData() {
            loadSummaryData();
        }

        function loadSummaryData() {
            $.ajax({
                url: '/reports/commission-data',
                type: 'GET',
                data: currentFilters,
                timeout: 10000, // 10 second timeout
                success: function(response) {
                    if (response.success) {
                        const data = response.data;

                        $('#totalCommissions').text((data.total_commissions || 0).toLocaleString());
                        $('#totalAdminCommission').text(currencySymbol + (data.total_admin_commission || 0).toLocaleString());
                        $('#totalInstructorCommission').text(currencySymbol + (data.total_instructor_commission || 0).toLocaleString());
                        $('#paidCommissions').text((data.paid_commissions || 0).toLocaleString());

                        // Load top instructors
                        loadTopInstructors(data.top_earning_instructors || []);

                        // Load commission by course
                        loadCommissionByCourse(data.commission_by_course || []);

                        // Load table data
                        loadTableData();
                    } else {
                        console.error('Commission data error:', response.message);
                        showError('Commission data error: ' + (response.message || 'Unknown error'));
                    }
                    showLoading(false);
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load commission data:', {xhr: xhr.responseText, status: status, error: error});
                    if (status === 'timeout') {
                        showError('Request timed out. Please try again.');
                    } else {
                        showError('Failed to load commission data: ' + error);
                    }
                    showLoading(false);
                }
            });
        }

        function loadChartData() {
            const chartFilters = {
                ...currentFilters,
                group_by: $('#chartGroupBy').val() || 'month'
            };

            $.get('/reports/commission-data', chartFilters, function(response) {
                if (response.success) {
                    updateCommissionChart(response.data);
                }
                showLoading(false);
            }).fail(function() {
                showError('Failed to load chart data');
                showLoading(false);
            });
        }

        function loadTableData() {
            const tableFilters = {
                ...currentFilters,
                per_page: $('#perPage').val() || 15,
                page: currentPage
            };

            $.get('/reports/commission-data', tableFilters, function(response) {
                if (response.success) {
                    updateCommissionTable(response.data.commission_list);
                }
                showLoading(false);
            }).fail(function() {
                showError('Failed to load table data');
                showLoading(false);
            });
        }

        function loadTopInstructors(topInstructors) {
            let html = '';
            if (Array.isArray(topInstructors) && topInstructors.length > 0) {
                topInstructors.forEach((instructor, index) => {
                    const instructorName = instructor.instructor?.name || 'Unknown Instructor';
                    const totalCommission = instructor.total_commission || 0;
                    const commissionCount = instructor.commission_count || 0;

                    html += `
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <div>
                                <span class="badge badge-primary">#${index + 1}</span>
                                <strong class="ml-2">${instructorName}</strong>
                            </div>
                            <div class="text-right">
                                <div>${currencySymbol}${totalCommission.toLocaleString()}</div>
                                <small class="text-muted">${commissionCount} commissions</small>
                            </div>
                        </div>
                    `;
                });
            } else {
                html = '<div class="text-center text-muted"> {{ __('No top instructors data available') }} </div>';
            }
            $('#topInstructorsList').html(html);
        }

        function loadCommissionByCourse(commissionByCourse) {
            let html = '';
            if (Array.isArray(commissionByCourse) && commissionByCourse.length > 0) {
                commissionByCourse.slice(0, 5).forEach((course, index) => {
                    const courseTitle = course.course?.title || 'Unknown Course';
                    const totalCommission = course.total_commission || 0;
                    const commissionCount = course.commission_count || 0;

                    html += `
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <div>
                                <span class="badge badge-success">#${index + 1}</span>
                                <strong class="ml-2">${courseTitle}</strong>
                            </div>
                            <div class="text-right">
                                <div>${currencySymbol}${totalCommission.toLocaleString()}</div>
                                <small class="text-muted">${commissionCount} commissions</small>
                            </div>
                        </div>
                    `;
                });
            } else {
                html = '<div class="text-center text-muted"> {{ __('No commission by course data available') }} </div>';
            }
            $('#commissionByCourseList').html(html);
        }


        function updateCommissionTable(tableData) {
            let html = '';

            // Handle both paginated (with .data property) and non-paginated (direct array) data
            const commissions = Array.isArray(tableData) ? tableData : (tableData.data || []);

            if (commissions.length === 0) {
                html = '<tr><td colspan="9" class="text-center"> {{ __('No commission data available') }} </td></tr>';
            } else {
                commissions.forEach(commission => {
                    const statusBadge = getStatusBadge(commission.status);
                    const typeBadge = getTypeBadge(commission.instructor_type);

                    // Parse dates properly to avoid deprecation warnings
                    const createdAt = commission.created_at ? moment(commission.created_at, moment.ISO_8601).format('DD MMM YYYY') : 'N/A';
                    const paidAt = commission.paid_at ? moment(commission.paid_at, moment.ISO_8601).format('DD MMM YYYY') : '-';

                    html += `
                        <tr>
                            <td>${createdAt}</td>
                            <td>${commission.instructor?.name || 'N/A'}</td>
                            <td>${commission.course?.title || 'N/A'}</td>
                            <td>${typeBadge}</td>
                            <td>${currencySymbol}${(commission.discounted_price || commission.course_price || 0).toLocaleString()}</td>
                            <td>${currencySymbol}${(commission.admin_commission_amount || 0).toLocaleString()}</td>
                            <td>${currencySymbol}${(commission.instructor_commission_amount || 0).toLocaleString()}</td>
                            <td>${statusBadge}</td>
                            <td>${paidAt}</td>
                        </tr>
                    `;
                });
            }

            $('#commissionTableBody').html(html);

            // Update pagination only if we have paginated data
            if (tableData && !Array.isArray(tableData) && tableData.total !== undefined) {
                updatePagination(tableData);
            }
        }

        function getStatusBadge(status) {
            const badges = {
                'pending': '<span class="badge badge-warning">{{ __('Pending') }}</span>',
                'paid': '<span class="badge badge-success">{{ __('Paid') }}</span>',
                'cancelled': '<span class="badge badge-danger">{{ __('Cancelled') }}</span>'
            };
            return badges[status] || `<span class="badge badge-secondary">${status}</span>`;
        }

        function getTypeBadge(type) {
            const badges = {
                'individual': '<span class="badge badge-info">{{ __('Individual') }}</span>',
                'team': '<span class="badge badge-primary">{{ __('Team') }}</span>'
            };
            return badges[type] || `<span class="badge badge-secondary">${type}</span>`;
        }

        function updatePagination(data) {
            const paginationInfo = `Showing ${data.from || 1} to ${data.to || data.data.length} of ${data.total || data.data.length} entries`;
            $('#pagination-info').html(paginationInfo);

            if (data.last_page > 1) {
                const currentPage = data.current_page || 1;
                const lastPage = data.last_page;
                let paginationHtml = '';

                // Previous button
                if (currentPage > 1) {
                    paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(${currentPage - 1}); return false;">&laquo; Previous</a></li>`;
                } else {
                    paginationHtml += `<li class="page-item disabled"><span class="page-link">&laquo; Previous</span></li>`;
                }

                // Calculate page range (show 5 pages around current)
                const startPage = Math.max(1, currentPage - 2);
                const endPage = Math.min(lastPage, currentPage + 2);

                // Show first page if not in range
                if (startPage > 1) {
                    paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(1); return false;">1</a></li>`;
                    if (startPage > 2) {
                        paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                    }
                }

                // Show page numbers
                for (let i = startPage; i <= endPage; i++) {
                    const active = i === currentPage ? 'active' : '';
                    paginationHtml += `<li class="page-item ${active}"><a class="page-link" href="#" onclick="goToPage(${i}); return false;">${i}</a></li>`;
                }

                // Show last page if not in range
                if (endPage < lastPage) {
                    if (endPage < lastPage - 1) {
                        paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                    }
                    paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(${lastPage}); return false;">${lastPage}</a></li>`;
                }

                // Next button
                if (currentPage < lastPage) {
                    paginationHtml += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(${currentPage + 1}); return false;">Next &raquo;</a></li>`;
                } else {
                    paginationHtml += `<li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>`;
                }

                $('#pagination').html(paginationHtml);
            } else {
                $('#pagination').empty();
            }
        }

        function goToPage(page) {
            currentPage = page;
            loadTableData();
        }


        function refreshData() {
            applyFilters();
        }

        function getFilterValues() {
            const filters = {
                course_id: $('#courseFilter').val(),
                instructor_id: $('#instructorFilter').val(),
                status: $('#statusFilter').val(),
                instructor_type: $('#instructorTypeFilter').val()
            };

            // Handle date range
            const dateRangeValue = $('#dateRange').val();
            if (dateRangeValue && dateRangeValue.includes(' - ')) {
                const dateRange = dateRangeValue.split(' - ');
                if (dateRange.length === 2) {
                    const dateFrom = moment(dateRange[0].trim(), 'DD/MM/YYYY');
                    const dateTo = moment(dateRange[1].trim(), 'DD/MM/YYYY');
                    if (dateFrom.isValid()) {
                        filters.date_from = dateFrom.format('YYYY-MM-DD');
                    }
                    if (dateTo.isValid()) {
                        filters.date_to = dateTo.format('YYYY-MM-DD');
                    }
                }
            }

            // Remove empty values
            Object.keys(filters).forEach(key => {
                if (!filters[key]) {
                    delete filters[key];
                }
            });

            return filters;
        }

        function exportReport(format) {
            // Get current filter values from the form
            const filters = getFilterValues();

            // Create a form and submit it to the export endpoint
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/reports/commission/export';
            form.target = '_blank';

            // Add CSRF token
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_token';
            csrfInput.value = '{{ csrf_token() }}';
            form.appendChild(csrfInput);

            // Add format
            const formatInput = document.createElement('input');
            formatInput.type = 'hidden';
            formatInput.name = 'export_format';
            formatInput.value = format;
            form.appendChild(formatInput);

            // Add filters
            Object.keys(filters).forEach(key => {
                if (filters[key]) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = filters[key];
                    form.appendChild(input);
                }
            });

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        function showLoading(show) {
            if (show) {
                $('#loading-state').show();
                $('#summary-section, #table-section, #top-instructors-section').addClass('opacity-50');

                // Safety timeout - ensure loading is hidden after 15 seconds max
                setTimeout(function() {
                    if ($('#loading-state').is(':visible')) {
                        console.warn('Loading timeout - forcing hide');
                        showLoading(false);
                        showError('Request is taking too long. Please try refreshing the page.');
                    }
                }, 15000);
            } else {
                $('#loading-state').hide();
                $('#summary-section, #chart-section, #table-section, #top-instructors-section').removeClass('opacity-50');
            }
        }

        function showError(message) {
            alert(message);
        }
    </script>
@endpush

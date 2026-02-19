@extends('layouts.app')

@section('title')
    {{ __('Enrollment Reports') }}
@endsection

@push('style')
    <link rel="stylesheet" href="{{ asset('library/bootstrap-daterangepicker/daterangepicker.css') }}">
    <link rel="stylesheet" href="{{ asset('library/select2/dist/css/select2.min.css') }}">
@endpush

@section('main')
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-user-graduate"></i> {{ __('Enrollment Reports') }} </h1>
            <div class="section-header-button">
                @can('reports-enrollment-export')
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
                            <label> {{ __('Category') }} </label>
                            <select class="form-control select2" id="categoryFilter" name="category_id">
                                <option value=""> {{ __('All Categories') }} </option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label> {{ __('Status') }} </label>
                            <select class="form-control" id="statusFilter" name="status">
                                <option value=""> {{ __('All Status') }} </option>
                                <option value="started"> {{ __('Started') }} </option>
                                <option value="in_progress"> {{ __('In Progress') }} </option>
                                <option value="completed"> {{ __('Completed') }} </option>
                            </select>
                        </div>
                    </div>
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
            <p class="mt-2 text-muted"> {{ __('Loading enrollment data...') }} </p>
        </div>

        <!-- Summary Cards -->
        <div id="summary-section">
            <div class="row">
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-primary">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Total Enrollments') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="totalEnrollments"> {{ __('0') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Completed') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="completedEnrollments"> {{ __('0') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-warning">
                            <i class="fas fa-play-circle"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('In Progress') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="inProgressEnrollments"> {{ __('0') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-info">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Completion Rate') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="completionRate"> {{ __('0') }} {{ __('%') }}</span>  </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chart Section -->
        <div id="chart-section" class="row mt-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-pie"></i> {{ __('Enrollment Status') }} </h4>
                    </div>
                    <div class="card-body">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Table -->
        <div id="table-section" class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-table"></i> {{ __('Enrollment Data') }} </h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" id="enrollmentTable">
                                <thead>
                                    <tr>
                                        <th> {{ __('Student') }} </th>
                                        <th> {{ __('Course') }} </th>
                                        <th> {{ __('Instructor') }} </th>
                                        <th> {{ __('Category') }} </th>
                                        <th> {{ __('Enrolled Date') }} </th>
                                        <th> {{ __('Status') }} </th>
                                        <th> {{ __('Progress') }} </th>
                                    </tr>
                                </thead>
                                <tbody id="enrollmentTableBody">
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

        <!-- Top Courses Section -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-trophy"></i> {{ __('Top Enrolled Courses') }} </h4>
                    </div>
                    <div class="card-body">
                        <div id="topCoursesList">
                            <!-- Top courses will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-calendar-alt"></i> {{ __('Monthly Enrollments') }} </h4>
                    </div>
                    <div class="card-body">
                        <div id="monthlyEnrollmentsList">
                            <!-- Monthly data will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('library/moment/min/moment.min.js') }}"></script>
    <script src="{{ asset('library/bootstrap-daterangepicker/daterangepicker.js') }}"></script>
    <script src="{{ asset('library/select2/dist/js/select2.full.min.js') }}"></script>
    <script src="{{ asset('library/chart.js/dist/Chart.min.js') }}"></script>

    <script>
        let statusChart;
        let currentPage = 1;
        let currentFilters = {};
        const currencySymbol = '{{ $currency_symbol ?? "₹" }}';

        $(document).ready(function() {
            initializePage();
            loadInitialData();
        });

        function initializePage() {
            $('#dateRange').daterangepicker({
                locale: { format: 'DD/MM/YYYY' },
                startDate: moment().subtract(29, 'days'),
                endDate: moment(),
                ranges: {
                    'Today': [moment(), moment()],
                    'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                    'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                    'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                    'This Month': [moment().startOf('month'), moment().endOf('month')],
                    'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
                }
            });

            $('.select2').select2({
                placeholder: 'Select an option',
                allowClear: true
            });

            loadFilterOptions();
        }

        function loadFilterOptions() {
            $.get('/reports/filters', function(response) {
                if (response.success) {
                    const data = response.data;

                    $('#courseFilter').empty().append('<option value=""> {{ __('All Courses') }} </option>');
                    data.courses.forEach(course => {
                        $('#courseFilter').append(`<option value="${course.id}">${course.title}</option>`);
                    });

                    $('#instructorFilter').empty().append('<option value=""> {{ __('All Instructors') }} </option>');
                    data.instructors.forEach(instructor => {
                        $('#instructorFilter').append(`<option value="${instructor.id}">${instructor.name}</option>`);
                    });

                    $('#categoryFilter').empty().append('<option value=""> {{ __('All Categories') }} </option>');
                    data.categories.forEach(category => {
                        $('#categoryFilter').append(`<option value="${category.id}">${category.name}</option>`);
                    });
                }
            });
        }

        function loadInitialData() {
            applyFilters();
        }

        function applyFilters() {
            showLoading(true);

            const dateRange = $('#dateRange').val().split(' - ');
            currentFilters = {
                date_from: moment(dateRange[0], 'DD/MM/YYYY').format('YYYY-MM-DD'),
                date_to: moment(dateRange[1], 'DD/MM/YYYY').format('YYYY-MM-DD'),
                course_id: $('#courseFilter').val(),
                instructor_id: $('#instructorFilter').val(),
                category_id: $('#categoryFilter').val(),
                status: $('#statusFilter').val()
            };

            Object.keys(currentFilters).forEach(key => {
                if (!currentFilters[key]) {
                    delete currentFilters[key];
                }
            });

            loadEnrollmentData();
        }

        function loadEnrollmentData() {
            loadSummaryData();
        }

        function loadSummaryData() {
            $.get('/reports/enrollment-data', currentFilters, function(response) {
                if (response.success) {
                    const data = response.data;

                    $('#totalEnrollments').text((data.total_enrollments || 0).toLocaleString());
                    $('#completedEnrollments').text((data.completed_enrollments || 0).toLocaleString());
                    $('#inProgressEnrollments').text((data.in_progress_enrollments || 0).toLocaleString());
                    $('#completionRate').text(data.completion_rate || 0);

                    loadTopCourses(data.enrollments_by_course || []);
                    loadMonthlyEnrollments(data.enrollments_by_month || {});

                    // Load status chart
                    loadStatusChart({
                        'Started': data.started_enrollments || 0,
                        'In Progress': data.in_progress_enrollments || 0,
                        'Completed': data.completed_enrollments || 0
                    });

                    loadTableData();
                }
                showLoading(false);
            }).fail(function() {
                showError('Failed to load enrollment data');
                showLoading(false);
            });
        }

        function loadTableData() {
            const tableFilters = {
                ...currentFilters,
                per_page: 15,
                page: currentPage
            };

            $.get('/reports/enrollment-data', tableFilters, function(response) {
                if (response.success) {
                    updateEnrollmentTable(response.data);
                }
                showLoading(false);
            }).fail(function() {
                showError('Failed to load table data');
                showLoading(false);
            });
        }

        function loadTopCourses(enrollmentsByCourse) {
            let html = '';
            if (Array.isArray(enrollmentsByCourse)) {
                enrollmentsByCourse.slice(0, 5).forEach((course, index) => {
                html += `
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                        <div>
                            <span class="badge badge-primary">#${index + 1}</span>
                            <strong class="ml-2">${course.course.title}</strong>
                        </div>
                        <div class="text-right">
                            <div>${course.enrollment_count} enrollments</div>
                            <small class="text-muted">${course.completed_count} completed</small>
                        </div>
                    </div>
                `;
                });
            }
            $('#topCoursesList').html(html);
        }

        function loadMonthlyEnrollments(monthlyData) {
            let html = '';
            Object.entries(monthlyData).forEach(([month, count]) => {
                html += `
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                        <div>
                            <strong>${moment(month + '-01', 'YYYY-MM-DD').format('MMMM YYYY')}</strong>
                        </div>
                        <div>
                            <span class="badge badge-info">${count} enrollments</span>
                        </div>
                    </div>
                `;
            });
            $('#monthlyEnrollmentsList').html(html);
        }

        function loadStatusChart(statusData) {
            const ctx = document.getElementById('statusChart').getContext('2d');

            if (statusChart) {
                statusChart.destroy();
            }

            const labels = Object.keys(statusData);
            const data = Object.values(statusData);

            statusChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: ['#6777ef', '#ffa426', '#28a745']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: {
                        position: 'bottom'
                    }
                }
            });
        }


        function updateEnrollmentTable(tableData) {
            let html = '';
            if (Array.isArray(tableData.data)) {
                tableData.data.forEach(enrollment => {
                const statusBadge = getStatusBadge(enrollment.status);

                html += `
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <img src="${enrollment.user?.profile || '/img/avatar/avatar-1.png'}" class="rounded-circle mr-2" width="30" height="30">
                                <div>
                                    <strong>${enrollment.user?.name || 'N/A'}</strong><br>
                                    <small class="text-muted">${enrollment.user?.email || 'N/A'}</small>
                                </div>
                            </div>
                        </td>
                        <td>${enrollment.course?.title || 'N/A'}</td>
                        <td>${enrollment.course?.user?.name || 'N/A'}</td>
                        <td>${enrollment.course?.category?.name || 'N/A'}</td>
                        <td>${moment(enrollment.created_at, moment.ISO_8601).format('DD MMM YYYY')}</td>
                        <td>${statusBadge}</td>
                        <td>
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar" role="progressbar" style="width: ${enrollment.progress || 0}%" aria-valuenow="${enrollment.progress || 0}" aria-valuemin="0" aria-valuemax="100">
                                    ${enrollment.progress || 0}%
                                </div>
                            </div>
                        </td>
                    </tr>
                `;
                });
            }
            $('#enrollmentTableBody').html(html);

            updatePagination(tableData);
        }

        function getStatusBadge(status) {
            const badges = {
                'started': '<span class="badge badge-info">{{ __('Started') }}</span>',
                'in_progress': '<span class="badge badge-warning">{{ __('In Progress') }}</span>',
                'completed': '<span class="badge badge-success">{{ __('Completed') }}</span>'
            };
            return badges[status] || `<span class="badge badge-secondary">${status}</span>`;
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
                category_id: $('#categoryFilter').val(),
                status: $('#statusFilter').val()
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
            form.action = '/reports/enrollment/export';
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
            formatInput.name = 'format';
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
                $('#summary-section, #chart-section, #table-section').addClass('opacity-50');
            } else {
                $('#loading-state').hide();
                $('#summary-section, #chart-section, #table-section').removeClass('opacity-50');
            }
        }

        function showError(message) {
            alert(message);
        }
    </script>
@endpush

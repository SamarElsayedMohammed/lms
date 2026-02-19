@extends('layouts.app')

@section('title')
    {{ __('Course Reports') }}
@endsection
@push('style')
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="{{ asset('library/bootstrap-daterangepicker/daterangepicker.css') }}">
    <link rel="stylesheet" href="{{ asset('library/select2/dist/css/select2.min.css') }}">
@endpush

@section('main')
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-book"></i> {{ __('Course Reports') }} </h1>
            <div class="section-header-button">
                @can('reports-course-export')
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
                            <input type="text" class="form-control" id="dateRange" name="dateRange" placeholder="{{ __('All Time') }}" readonly>
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
                                <option value="active"> {{ __('Active') }} </option>
                                <option value="inactive"> {{ __('Inactive') }} </option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label> {{ __('Course Type') }} </label>
                            <select class="form-control" id="courseTypeFilter" name="course_type">
                                <option value=""> {{ __('All Types') }} </option>
                                <option value="free"> {{ __('Free') }} </option>
                                <option value="paid"> {{ __('Paid') }} </option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label> {{ __('Level') }} </label>
                            <select class="form-control" id="levelFilter" name="level">
                                <option value=""> {{ __('All Levels') }} </option>
                                <option value="beginner"> {{ __('Beginner') }} </option>
                                <option value="intermediate"> {{ __('Intermediate') }} </option>
                                <option value="advanced"> {{ __('Advanced') }} </option>
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
            <p class="mt-2 text-muted"> {{ __('Loading course data...') }} </p>
        </div>

        <!-- Summary Cards -->
        <div id="summary-section">
            <div class="row">
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-primary">
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Total Courses') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="totalCourses"> {{ __('0') }} </span>
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
                                <h4> {{ __('Active Courses') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="activeCourses"> {{ __('0') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-warning">
                            <i class="fas fa-users"></i>
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
                        <div class="card-icon bg-info">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Average Rating') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="averageRating"> {{ __('0') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Course Performance Table -->
        <div id="table-section" class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-bar"></i> {{ __('Course Performance') }} </h4>
                        <div class="card-header-action">
                            <select class="form-control" id="reportType" onchange="loadData()">
                                <option value="summary" selected> {{ __('Summary') }} </option>
                                <option value="detailed"> {{ __('Detailed') }} </option>
                                <option value="performance"> {{ __('Performance') }} </option>
                            </select>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" id="courseTable">
                                <thead>
                                    <tr>
                                        <th> {{ __('Course') }} </th>
                                        <th> {{ __('Instructor') }} </th>
                                        <th> {{ __('Category') }} </th>
                                        <th> {{ __('Type') }} </th>
                                        <th> {{ __('Level') }} </th>
                                        <th> {{ __('Enrollments') }} </th>
                                        <th> {{ __('Revenue') }} </th>
                                        <th> {{ __('Rating') }} </th>
                                        <th> {{ __('Status') }} </th>
                                    </tr>
                                </thead>
                                <tbody id="courseTableBody">
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

        <!-- Charts Section -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-pie"></i> {{ __('Courses by Category') }} </h4>
                    </div>
                    <div class="card-body">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-doughnut"></i> {{ __('Courses by Level') }} </h4>
                    </div>
                    <div class="card-body">
                        <canvas id="levelChart"></canvas>
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

    <script>
        let categoryChart, levelChart;
        let currentPage = 1;
        let currentFilters = {};
        const currencySymbol = '{{ $currency_symbol ?? "₹" }}';

        $(document).ready(function() {
            initializePage();
            loadInitialData();
        });

        function initializePage() {
            // Initialize date range picker with default "Last 30 Days" range
            $('#dateRange').daterangepicker({
                startDate: moment().subtract(29, 'days'),
                endDate: moment(),
                locale: {
                    format: 'DD/MM/YYYY',
                    cancelLabel: 'Clear',
                    customRangeLabel: 'Custom Range'
                },
                ranges: {
                    'Today': [moment(), moment()],
                    'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                    'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                    'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                    'This Month': [moment().startOf('month'), moment().endOf('month')],
                    'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
                },
                opens: 'left',
                showDropdowns: true,
                alwaysShowCalendars: false
            });

            // Set initial date range value
            const startDate = moment().subtract(29, 'days').format('DD/MM/YYYY');
            const endDate = moment().format('DD/MM/YYYY');
            $('#dateRange').val(startDate + ' - ' + endDate);

            // Handle date range selection
            $('#dateRange').on('apply.daterangepicker', function(ev, picker) {
                const dateRangeText = picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY');
                $(this).val(dateRangeText);
            });

            $('#dateRange').on('cancel.daterangepicker', function(ev, picker) {
                $(this).val('');
                // Reload data when date range is cleared
                applyFilters();
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

                    // Populate categories
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

            // Get filter values
            currentFilters = getFilterValues();

            loadData();
        }

        function getFilterValues() {
            const filters = {
                course_id: $('#courseFilter').val(),
                instructor_id: $('#instructorFilter').val(),
                category_id: $('#categoryFilter').val(),
                status: $('#statusFilter').val(),
                course_type: $('#courseTypeFilter').val(),
                level: $('#levelFilter').val()
            };

            // Only add report_type if reportType dropdown exists and has a value
            const reportType = $('#reportType').val();
            if (reportType) {
                filters.report_type = reportType;
            } else {
                // Default to summary if no report type is selected
                filters.report_type = 'summary';
            }

            // Handle date range - only add if date range is actually selected
            const dateRangeValue = $('#dateRange').val();
            if (dateRangeValue && dateRangeValue.trim() !== '' && dateRangeValue.includes(' - ')) {
                const dateRange = dateRangeValue.split(' - ');
                if (dateRange.length === 2) {
                    const dateFrom = moment(dateRange[0].trim(), 'DD/MM/YYYY');
                    const dateTo = moment(dateRange[1].trim(), 'DD/MM/YYYY');
                    if (dateFrom.isValid() && dateTo.isValid()) {
                        filters.date_from = dateFrom.format('YYYY-MM-DD');
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

        function loadData() {
            console.log('Loading course data with filters:', currentFilters);

            $.get('/reports/course-data', currentFilters, function(response) {
                console.log('Course data response:', response);

                if (response && response.success) {
                    const data = response.data;
                    console.log('Course data:', data);

                    if (currentFilters.report_type === 'performance') {
                        updatePerformanceTable(data);
                    } else {
                        updateSummaryData(data);
                        updateCourseTable(data.courses);
                    }
                } else {
                    console.error('Course data error:', response ? response.message : 'No response data');
                    showError('Failed to load course data: ' + (response ? response.message : 'Unknown error'));
                }
                showLoading(false);
            }).fail(function(xhr, status, error) {
                console.error('Failed to load course data:', {xhr: xhr.responseText, status: status, error: error});
                showError('Failed to load course data: ' + error);
                showLoading(false);
            });
        }

        function updateSummaryData(data) {
            console.log('Updating summary data:', data);
            console.log('Total courses:', data.total_courses);
            console.log('Active courses:', data.active_courses);
            console.log('Total enrollments:', data.total_enrollments);
            console.log('Average rating:', data.average_rating);

            // Ensure we have valid numbers
            const totalCourses = parseInt(data.total_courses) || 0;
            const activeCourses = parseInt(data.active_courses) || 0;
            const totalEnrollments = parseInt(data.total_enrollments) || 0;
            const averageRating = parseFloat(data.average_rating) || 0;

            $('#totalCourses').text(totalCourses.toLocaleString());
            $('#activeCourses').text(activeCourses.toLocaleString());
            $('#totalEnrollments').text(totalEnrollments.toLocaleString());
            $('#averageRating').text(averageRating > 0 ? averageRating.toFixed(1) : '0.0');

            // Update charts
            updateCategoryChart(data.courses_by_category);
            updateLevelChart(data.courses_by_level);
        }

        function updatePerformanceTable(data) {
            let html = '';
            data.forEach(item => {
                const course = item.course;
                const metrics = item.performance_metrics;

                html += `
                    <tr>
                        <td>
                            <strong>${course.title}</strong><br>
                            <small class="text-muted">Created: ${course.created_at ? moment(course.created_at, moment.ISO_8601).format('DD MMM YYYY') : 'N/A'}</small>
                        </td>
                        <td>${course.user?.name || 'N/A'}</td>
                        <td>${course.category?.name || 'N/A'}</td>
                        <td><span class="badge badge-${course.course_type === 'free' ? 'success' : 'primary'}">${course.course_type}</span></td>
                        <td><span class="badge badge-info">${course.level}</span></td>
                        <td>${metrics.enrollments.toLocaleString()}</td>
                        <td>${currencySymbol}${metrics.revenue?.toLocaleString() || '0'}</td>
                        <td>
                            <div class="d-flex align-items-center">
                                <span class="mr-1">${metrics.rating.toFixed(1)}</span>
                                <i class="fas fa-star text-warning"></i>
                                <small class="text-muted ml-1">(${metrics.reviews_count})</small>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-${course.is_active ? 'success' : 'danger'}">
                                ${course.is_active ? 'Active' : 'Inactive'}
                            </span>
                        </td>
                    </tr>
                `;
            });
            $('#courseTableBody').html(html);
        }

        function updateCourseTable(courses) {
            let html = '';

            // Ensure courses is an array
            if (!Array.isArray(courses)) {
                courses = [];
            }

            if (courses.length === 0) {
                html = '<tr><td colspan="9" class="text-center"> {{ __('No courses found') }} </td></tr>';
            } else {
                courses.forEach(course => {
                html += `
                    <tr>
                        <td>
                            <strong>${course.title}</strong><br>
                            <small class="text-muted">Created: ${course.created_at ? moment(course.created_at, moment.ISO_8601).format('DD MMM YYYY') : 'N/A'}</small>
                        </td>
                        <td>${course.user?.name || 'N/A'}</td>
                        <td>${course.category?.name || 'N/A'}</td>
                        <td><span class="badge badge-${course.course_type === 'free' ? 'success' : 'primary'}">${course.course_type}</span></td>
                        <td><span class="badge badge-info">${course.level}</span></td>
                        <td>${course.order_courses_count || 0}</td>
                        <td>${currencySymbol}${course.price?.toLocaleString() || '0'}</td>
                        <td>
                            <div class="d-flex align-items-center">
                                <span class="mr-1">${course.ratings_avg_rating?.toFixed(1) || '0.0'}</span>
                                <i class="fas fa-star text-warning"></i>
                                <small class="text-muted ml-1">(${course.ratings_count || 0})</small>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-${course.is_active ? 'success' : 'danger'}">
                                ${course.is_active ? 'Active' : 'Inactive'}
                            </span>
                        </td>
                    </tr>
                `;
                });
            }
            $('#courseTableBody').html(html);
        }

        function updateCategoryChart(categoriesData) {
            const ctx = document.getElementById('categoryChart');
            if (!ctx) return;

            const chartCtx = ctx.getContext('2d');

            if (categoryChart) {
                categoryChart.destroy();
            }

            // Handle array format from backend: [{category: 'Name', count: 5}, ...]
            let labels = [];
            let data = [];

            if (Array.isArray(categoriesData)) {
                labels = categoriesData.map(item => item.category || item.name || 'Unknown');
                data = categoriesData.map(item => item.count || 0);
            } else if (typeof categoriesData === 'object' && categoriesData !== null) {
                // Handle object format: {category: count, ...}
                labels = Object.keys(categoriesData);
                data = Object.values(categoriesData);
            }

            // Generate colors dynamically if we have more categories than predefined colors
            const baseColors = [
                '#6777ef', '#fc544b', '#ffa426', '#3abaf4', '#1abc9c',
                '#e83e8c', '#6f42c1', '#fd7e14', '#20c997', '#17a2b8',
                '#6c757d', '#28a745', '#dc3545', '#ffc107', '#007bff',
                '#6610f2', '#e83e8c', '#fd7e14', '#20c997', '#17a2b8'
            ];
            const backgroundColor = labels.map((_, index) => baseColors[index % baseColors.length]);

            categoryChart = new Chart(chartCtx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: backgroundColor
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: {
                        position: 'bottom',
                        display: true
                    },
                    tooltips: {
                        enabled: true
                    }
                }
            });
        }

        function updateLevelChart(levelsData) {
            const ctx = document.getElementById('levelChart');
            if (!ctx) return;

            const chartCtx = ctx.getContext('2d');

            if (levelChart) {
                levelChart.destroy();
            }

            // Handle array format from backend: [{level: 'beginner', count: 5}, ...]
            let labels = [];
            let data = [];

            if (Array.isArray(levelsData)) {
                labels = levelsData.map(item => {
                    const level = item.level || item.name || 'Unknown';
                    return level.charAt(0).toUpperCase() + level.slice(1);
                });
                data = levelsData.map(item => item.count || 0);
            } else if (typeof levelsData === 'object' && levelsData !== null) {
                // Handle object format: {level: count, ...}
                labels = Object.keys(levelsData).map(label => label.charAt(0).toUpperCase() + label.slice(1));
                data = Object.values(levelsData);
            }

            levelChart = new Chart(chartCtx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: ['#6777ef', '#fc544b', '#ffa426', '#3abaf4', '#1abc9c']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: {
                        position: 'bottom',
                        display: true
                    },
                    tooltips: {
                        enabled: true
                    }
                }
            });
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
            currentFilters.page = page;
            loadData();
        }

        function refreshData() {
            applyFilters();
        }

        function exportReport(format) {
            // Get current filter values from the form
            const filters = getFilterValues();

            // Remove report_type as it's not used in export
            delete filters.report_type;

            // Create a form and submit it to the export endpoint
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/reports/course/export';
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
                $('#summary-section, #table-section').addClass('opacity-50');
            } else {
                $('#loading-state').hide();
                $('#summary-section, #table-section').removeClass('opacity-50');
            }
        }

        function showError(message) {
            alert(message);
        }
    </script>
@endpush

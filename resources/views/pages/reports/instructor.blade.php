@extends('layouts.app')

@section('title')
    {{ __('Instructor Reports') }}
@endsection

@push('style')
    <link rel="stylesheet" href="{{ asset('library/bootstrap-daterangepicker/daterangepicker.css') }}">
    <link rel="stylesheet" href="{{ asset('library/select2/dist/css/select2.min.css') }}">
@endpush

@section('main')
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-chalkboard-teacher"></i> {{ __('Instructor Reports') }} </h1>
            <div class="section-header-button">
                @can('reports-instructor-export')
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
                    @if($shouldShowInstructorFilters ?? true)
                    <div class="col-md-3">
                        <div class="form-group">
                            <label> {{ __('Instructor') }} </label>
                            <select class="form-control select2" id="instructorFilter" name="instructor_id">
                                <option value=""> {{ __('All Instructors') }} </option>
                            </select>
                        </div>
                    </div>
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
                            <label> {{ __('Status') }} </label>
                            <select class="form-control" id="statusFilter" name="status">
                                <option value=""> {{ __('All Status') }} </option>
                                <option value="pending"> {{ __('Pending') }} </option>
                                <option value="approved"> {{ __('Approved') }} </option>
                                <option value="rejected"> {{ __('Rejected') }} </option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
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
            <p class="mt-2 text-muted"> {{ __('Loading instructor data...') }} </p>
        </div>

        <!-- Summary Cards -->
        <div id="summary-section">
            <div class="row">
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-primary">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Total Instructors') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="totalInstructors"> {{ __('0') }} </span>
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
                                <h4> {{ __('Approved') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="approvedInstructors"> {{ __('0') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-warning">
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
                        <div class="card-icon bg-info">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Individual Type') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="individualInstructors"> {{ __('0') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Instructor Performance Table -->
        <div id="table-section" class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-table"></i> {{ __('Instructor Data') }} </h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" id="instructorTable">
                                <thead>
                                    <tr>
                                        <th> {{ __('Instructor') }} </th>
                                        <th> {{ __('Type') }} </th>
                                        <th> {{ __('Status') }} </th>
                                        <th> {{ __('Courses') }} </th>
                                        <th> {{ __('Enrollments') }} </th>
                                        <th> {{ __('Revenue') }} </th>
                                        <th> {{ __('Avg Rating') }} </th>
                                        <th> {{ __('Join Date') }} </th>
                                    </tr>
                                </thead>
                                <tbody id="instructorTableBody">
                                    <!-- Data will be loaded here -->
                                </tbody>
                            </table>
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

    <script>
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

            const dateRange = $('#dateRange').val().split(' - ');
            currentFilters = {
                date_from: moment(dateRange[0].trim(), 'DD/MM/YYYY').format('YYYY-MM-DD'),
                date_to: moment(dateRange[1].trim(), 'DD/MM/YYYY').format('YYYY-MM-DD'),
                instructor_id: $('#instructorFilter').val(),
                instructor_type: $('#instructorTypeFilter').val(),
                status: $('#statusFilter').val()
            };

            Object.keys(currentFilters).forEach(key => {
                if (!currentFilters[key]) {
                    delete currentFilters[key];
                }
            });

            loadData();
        }

        function loadData() {
            $.get('/reports/instructor-data', currentFilters, function(response) {
                if (response.success) {
                    const data = response.data;
                    updateSummaryData(data);
                    updateInstructorTable(data.instructors || data);
                }
                showLoading(false);
            }).fail(function() {
                showError('Failed to load instructor data');
                showLoading(false);
            });
        }

        function updateSummaryData(data) {
            $('#totalInstructors').text(data.total_instructors?.toLocaleString() || '0');
            $('#approvedInstructors').text(data.approved_instructors?.toLocaleString() || '0');
            $('#totalCourses').text(data.total_courses_created?.toLocaleString() || '0');
            $('#individualInstructors').text(data.individual_instructors?.toLocaleString() || '0');
        }

        function updatePerformanceTable(data) {
            // This function is not used anymore since we removed report_type dropdown
            // But keeping it for backward compatibility
            updateInstructorTable(data);
        }

        function updateInstructorTable(instructors) {
            let html = '';

            // Ensure instructors is an array
            if (!Array.isArray(instructors)) {
                instructors = [];
            }

            if (instructors.length === 0) {
                html = '<tr><td colspan="8" class="text-center"> {{ __('No instructors found') }} </td></tr>';
            } else {
                instructors.forEach(instructor => {
                html += `
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <img src="${instructor.profile || '/img/avatar/avatar-1.png'}" class="rounded-circle mr-2" width="40" height="40">
                                <div>
                                    <strong>${instructor.name || 'N/A'}</strong><br>
                                    <small class="text-muted">${instructor.email || 'N/A'}</small>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge badge-${instructor.instructor_details?.type === 'individual' ? 'info' : 'primary'}">${capitalizeFirst(instructor.instructor_details?.type) || 'N/A'}</span></td>
                        <td><span class="badge badge-${getStatusColor(instructor.instructor_details?.status)}">${capitalizeFirst(instructor.instructor_details?.status) || 'N/A'}</span></td>
                        <td>${instructor.total_courses || instructor.courses?.length || 0}</td>
                        <td>${instructor.total_enrollments?.toLocaleString() || '0'}</td>
                        <td>${currencySymbol}${instructor.total_revenue?.toLocaleString() || '0'}</td>
                        <td>
                            ${instructor.average_rating ? `
                                <div class="d-flex align-items-center">
                                    <span class="mr-1">${instructor.average_rating.toFixed(1)}</span>
                                    <i class="fas fa-star text-warning"></i>
                                </div>
                            ` : '-'}
                        </td>
                        <td>${instructor.created_at ? moment(instructor.created_at, moment.ISO_8601).format('DD MMM YYYY') : 'N/A'}</td>
                    </tr>
                `;
                });
            }
            $('#instructorTableBody').html(html);
        }

        function getStatusColor(status) {
            const colors = {
                'pending': 'warning',
                'approved': 'success',
                'rejected': 'danger'
            };
            return colors[status] || 'secondary';
        }

        function capitalizeFirst(str) {
            if (!str) return '';
            return str.charAt(0).toUpperCase() + str.slice(1);
        }

        function refreshData() {
            applyFilters();
        }

        function getFilterValues() {
            const filters = {
                instructor_id: $('#instructorFilter').val(),
                instructor_type: $('#instructorTypeFilter').val(),
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
            form.action = '/reports/instructor/export';
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

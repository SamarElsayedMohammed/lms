@extends('layouts.app')

@section('title')
    {{ __('Revenue Reports') }}
@endsection

@push('style')
    <link rel="stylesheet" href="{{ asset('library/bootstrap-daterangepicker/daterangepicker.css') }}">
    <link rel="stylesheet" href="{{ asset('library/select2/dist/css/select2.min.css') }}">
@endpush

@section('main')
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-money-bill-wave"></i> {{ __('Revenue Reports') }} </h1>
            <div class="section-header-button">
                @can('reports-revenue-export')
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
                            <label> {{ __('Payment Method') }} </label>
                            <select class="form-control" id="paymentMethodFilter" name="payment_method">
                                <option value=""> {{ __('All Methods') }} </option>
                                <option value="stripe"> {{ __('Stripe') }} </option>
                                <option value="razorpay"> {{ __('Razorpay') }} </option>
                                <option value="flutterwave"> {{ __('Flutterwave') }} </option>
                                <option value="wallet"> {{ __('Wallet') }} </option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>&nbsp;</label>
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
            <p class="mt-2 text-muted"> {{ __('Loading revenue data...') }} </p>
        </div>

        <!-- Summary Cards -->
        <div id="summary-section">
            <div class="row">
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card revenue-card">
                        <div class="card-icon bg-success">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Total Revenue') }} </h4>
                            </div>
                            <div class="card-body">  <span id="totalRevenue"> {{ $currency_symbol ?? '₹' }}{{ __('0') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-primary">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Total Orders') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="totalOrders"> {{ __('0') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-warning">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Avg Order Value') }} </h4>
                            </div>
                            <div class="card-body"> <span id="avgOrderValue"> {{ $currency_symbol ?? '₹' }}{{ __('0') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-info">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Payment Methods') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="paymentMethodsCount"> {{ __('0') }} </span>
                            </div>
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
                        <h4><i class="fas fa-chart-pie"></i> {{ __('Revenue by Payment Method') }} </h4>
                    </div>
                    <div class="card-body">
                        <canvas id="paymentMethodChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Comparison Section -->
        <div id="comparison-section" class="row mt-4" style="display: none;">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-bar"></i> {{ __('Revenue Comparison') }} </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h5> {{ __('Current Period') }} </h5>
                                        <h2 class="text-success"> {{ $currency_symbol ?? '₹' }} <span id="currentPeriodRevenue"> {{ __('0') }} </span></h2>
                                        <p><span id="currentPeriodOrders"> {{ __('0') }} </span> {{ __('orders') }} </p>
                                        <small class="text-muted"> {{ __('Avg:') }} {{ $currency_symbol ?? '₹' }} <span id="currentPeriodAvg"> {{ __('0') }} </span></small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h5> {{ __('Previous Period') }} </h5>
                                        <h2 class="text-info"> {{ $currency_symbol ?? '₹' }} <span id="previousPeriodRevenue"> {{ __('0') }} </span></h2>
                                        <p><span id="previousPeriodOrders"> {{ __('0') }} </span> {{ __('orders') }} </p>
                                        <small class="text-muted"> {{ __('Avg:') }} {{ $currency_symbol ?? '₹' }} <span id="previousPeriodAvg"> {{ __('0') }} </span></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="alert alert-info text-center">
                                    <h5> {{ __('Growth Analysis') }} </h5>
                                    <p class="mb-1"> {{ __('Revenue Growth:') }} <span id="revenueGrowth" class="font-weight-bold"> {{ __('0%') }} </span></p>
                                    <p class="mb-0"> {{ __('Orders Growth:') }} <span id="ordersGrowth" class="font-weight-bold"> {{ __('0%') }} </span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Revenue Sources -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-trophy"></i> {{ __('Top Revenue Courses') }} </h4>
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
                        <h4><i class="fas fa-chart-pie"></i> {{ __('Revenue by Category') }} </h4>
                    </div>
                    <div class="card-body">
                        <div id="revenueByCategoryList">
                            <!-- Revenue by category will be loaded here -->
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
        let paymentMethodChart;
        let currentFilters = {};
        const currencySymbol = '{{ $currency_symbol ?? "₹" }}';

        $(document).ready(function() {
            initializePage();
            loadInitialData();
        });

        function initializePage() {
            $('#dateRange').daterangepicker({
                locale: { format: 'DD/MM/YYYY' },
                startDate: moment().subtract(6, 'months'),
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
                payment_method: $('#paymentMethodFilter').val()
            };

            Object.keys(currentFilters).forEach(key => {
                if (!currentFilters[key]) {
                    delete currentFilters[key];
                }
            });

            loadRevenueData();
        }

        function loadRevenueData() {
            $('#comparison-section').hide();
            $('#chart-section').show();
            loadSummaryData();
        }

        function loadSummaryData() {
            $.get('/reports/revenue-data', currentFilters, function(response) {
                if (response.success) {
                    const data = response.data;

                    $('#totalRevenue').text((data.total_revenue || 0).toLocaleString());
                    $('#totalOrders').text((data.total_orders || 0).toLocaleString());
                    $('#avgOrderValue').text(Math.round(data.average_order_value || 0).toLocaleString());
                    $('#paymentMethodsCount').text(Object.keys(data.revenue_by_payment_method || {}).length);

                    loadTopCourses(data.top_revenue_courses || []);
                    loadRevenueByCategory(data.revenue_by_category || {});
                    loadPaymentMethodChart(data.revenue_by_payment_method || {});
                }
                showLoading(false);
            }).fail(function() {
                showError('Failed to load revenue data');
                showLoading(false);
            });
        }


        function loadComparisonData() {
            $.get('/reports/revenue-data', currentFilters, function(response) {
                if (response.success) {
                    const data = response.data;

                    $('#currentPeriodRevenue').text((data.current_period?.revenue || 0).toLocaleString());
                    $('#currentPeriodOrders').text((data.current_period?.orders || 0).toLocaleString());
                    $('#currentPeriodAvg').text(Math.round(data.current_period?.avg_order_value || 0).toLocaleString());

                    $('#previousPeriodRevenue').text((data.previous_period?.revenue || 0).toLocaleString());
                    $('#previousPeriodOrders').text((data.previous_period?.orders || 0).toLocaleString());
                    $('#previousPeriodAvg').text(Math.round(data.previous_period?.avg_order_value || 0).toLocaleString());

                    const revenueGrowth = data.growth?.revenue_growth || 0;
                    $('#revenueGrowth').text(revenueGrowth + '%')
                        .removeClass('text-success text-danger')
                        .addClass(revenueGrowth >= 0 ? 'text-success' : 'text-danger');

                    const ordersGrowth = data.growth?.orders_growth || 0;
                    $('#ordersGrowth').text(ordersGrowth + '%')
                        .removeClass('text-success text-danger')
                        .addClass(ordersGrowth >= 0 ? 'text-success' : 'text-danger');
                }
                showLoading(false);
            }).fail(function() {
                showError('Failed to load comparison data');
                showLoading(false);
            });
        }

        function loadTopCourses(topCourses) {
            let html = '';
            if (Array.isArray(topCourses)) {
                topCourses.slice(0, 5).forEach((course, index) => {
                html += `
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                        <div>
                            <span class="badge badge-primary">#${index + 1}</span>
                            <strong class="ml-2">${course.course.title}</strong>
                        </div>
                        <div class="text-right">
                            <div>${currencySymbol}${(course.revenue || 0).toLocaleString()}</div>
                            <small class="text-muted">${course.orders_count || 0} orders</small>
                        </div>
                    </div>
                `;
                });
            }
            $('#topCoursesList').html(html);
        }

        function loadRevenueByCategory(revenueByCategory) {
            let html = '';
            if (revenueByCategory && typeof revenueByCategory === 'object') {
                Object.entries(revenueByCategory).slice(0, 5).forEach(([category, revenue], index) => {
                html += `
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                        <div>
                            <span class="badge badge-success">#${index + 1}</span>
                            <strong class="ml-2">${category}</strong>
                        </div>
                        <div>
                            <span class="badge badge-info">${currencySymbol}${(revenue || 0).toLocaleString()}</span>
                        </div>
                    </div>
                `;
                });
            }
            $('#revenueByCategoryList').html(html);
        }

        function loadPaymentMethodChart(paymentMethodData) {
            const ctx = document.getElementById('paymentMethodChart').getContext('2d');

            if (paymentMethodChart) {
                paymentMethodChart.destroy();
            }

            const labels = Object.keys(paymentMethodData);
            const data = Object.values(paymentMethodData);

            paymentMethodChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels.map(label => label.charAt(0).toUpperCase() + label.slice(1)),
                    datasets: [{
                        data: data,
                        backgroundColor: ['#6777ef', '#fc544b', '#ffa426']
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


        function refreshData() {
            applyFilters();
        }

        function exportReport(format) {
            try {
                const filters = getFilterValues();
                delete filters.report_type; // Remove report_type as it's not used in export

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '/reports/revenue/export';
                form.target = '_blank';

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = '{{ csrf_token() }}';
                form.appendChild(csrfInput);

                const formatInput = document.createElement('input');
                formatInput.type = 'hidden';
                formatInput.name = 'format';
                formatInput.value = format;
                form.appendChild(formatInput);

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

                setTimeout(() => {
                    document.body.removeChild(form);
                }, 1000);
            } catch (error) {
                console.error('Export error:', error);
                alert('Failed to export report. Please try again.');
            }
        }

        function getFilterValues() {
            const filters = {
                course_id: $('#courseFilter').val(),
                instructor_id: $('#instructorFilter').val(),
                category_id: $('#categoryFilter').val(),
                payment_method: $('#paymentMethodFilter').val()
            };

            // Handle date range
            const dateRangeValue = $('#dateRange').val();
            if (dateRangeValue && dateRangeValue.includes(' - ')) {
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

        function showLoading(show) {
            if (show) {
                $('#loading-state').show();
                $('#summary-section, #chart-section, #comparison-section').addClass('opacity-50');
            } else {
                $('#loading-state').hide();
                $('#summary-section, #chart-section, #comparison-section').removeClass('opacity-50');
            }
        }

        function showError(message) {
            alert(message);
        }
    </script>
@endpush

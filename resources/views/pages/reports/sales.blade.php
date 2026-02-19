@extends('layouts.app')

@section('title')
    {{ __('Sales Reports') }}
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
            <h1><i class="fas fa-shopping-cart"></i> {{ __('Sales Reports') }} </h1>
            <div class="section-header-button">
                @can('reports-sales-export')
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
                                <option value="completed"> {{ __('Completed') }} </option>
                                <option value="cancelled"> {{ __('Cancelled') }} </option>
                                <option value="failed"> {{ __('Failed') }} </option>
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
                            <label> {{ __('Category') }} </label>
                            <select class="form-control select2" id="categoryFilter" name="category_id">
                                <option value=""> {{ __('All Categories') }} </option>
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
            <p class="mt-2 text-muted"> {{ __('Loading sales data...') }} </p>
        </div>

        <!-- Summary Cards -->
        <div id="summary-section">
            <div class="row">
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-primary">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>{{ __('Total Orders') }}</h4>
                            </div>
                            <div class="card-body">
                                <span id="totalOrders">0</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-success">
                            <i class="fas fa-rupee-sign"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>{{ __('Total Revenue') }}</h4>
                            </div>
                            <div class="card-body">
                                <span id="totalRevenue">{{ $currency_symbol ?? '₹' }}0</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-warning">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>{{ __('Avg Order Value') }}</h4>
                            </div>
                            <div class="card-body">
                                <span id="avgOrderValue">{{ $currency_symbol ?? '₹' }}0</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1 report-card">
                        <div class="card-icon bg-info">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>{{ __('Completed Orders') }}</h4>
                            </div>
                            <div class="card-body">
                                <span id="completedOrders">0</span>
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
                        <h4><i class="fas fa-table"></i> {{ __('Sales Data') }} </h4>
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
                            <table class="table table-striped" id="salesTable">
                                <thead>
                                    <tr>
                                        <th> {{ __('Order ID') }} </th>
                                        <th> {{ __('Date') }} </th>
                                        <th> {{ __('Customer') }} </th>
                                        <th> {{ __('Course') }} </th>
                                        <th> {{ __('Amount') }} </th>
                                        <th> {{ __('Payment Method') }} </th>
                                        <th> {{ __('Status') }} </th>
                                        <th> {{ __('Action') }} </th>
                                    </tr>
                                </thead>
                                <tbody id="salesTableBody">
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
        <div id="top-courses-section" class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-trophy"></i> {{ __('Top Selling Courses') }} </h4>
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
                        <h4><i class="fas fa-chart-pie"></i> {{ __('Payment Methods') }} </h4>
                    </div>
                    <div class="card-body">
                        <canvas id="paymentMethodsChart"></canvas>
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
        // Get currency symbol from system settings
        const currencySymbol = '{{ $currency_symbol ?? "₹" }}';
        let paymentMethodsChart;
        let currentPage = 1;
        let currentFilters = {};

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

                    // Populate categories
                    $('#categoryFilter').empty().append('<option value=""> {{ __('All Categories') }} </option>');
                    data.categories.forEach(category => {
                        $('#categoryFilter').append(`<option value="${category.id}">${category.name}</option>`);
                    });
                }
            });
        }

        function loadInitialData() {
            // Set default filters
            currentFilters = {
                date_from: moment().subtract(29, 'days').format('YYYY-MM-DD'),
                date_to: moment().format('YYYY-MM-DD')
            };

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
                    payment_method: $('#paymentMethodFilter').val(),
                    category_id: $('#categoryFilter').val()
                };

                // Remove empty values
                Object.keys(currentFilters).forEach(key => {
                    if (!currentFilters[key]) {
                        delete currentFilters[key];
                    }
                });

                loadSalesData();
            } catch (error) {
                console.error('Error in applyFilters:', error);
                showLoading(false);
                showError('Error processing filters: ' + error.message);
            }
        }

        function loadSalesData() {
            // Always load summary data first
            loadSummaryData();
        }

        function loadSummaryData() {
            console.log('Loading summary data with filters:', currentFilters);

            $.get('/reports/sales-data', currentFilters, function(response) {
                console.log('Summary data response:', response);

                if (response && response.success) {
                    const data = response.data;

                    // Update summary cards with proper formatting
                    $('#totalOrders').text((data.total_orders || 0).toLocaleString());
                    $('#totalRevenue').text(currencySymbol + (data.total_revenue || 0).toLocaleString());
                    $('#avgOrderValue').text(currencySymbol + Math.round(data.average_order_value || 0).toLocaleString());
                    $('#completedOrders').text((data.completed_orders || 0).toLocaleString());

                    // Load top courses
                    if (data.top_courses) {
                        loadTopCourses(data.top_courses);
                    }

                    // Load payment methods chart
                    if (data.payment_methods) {
                        loadPaymentMethodsChart(data.payment_methods);
                    }

                    // Load table data for detailed view
                    setTimeout(() => {
                        loadTableData();
                    }, 100);
                } else {
                    console.error('Summary data error:', response ? response.message : 'No response data');
                    // Set fallback data for summary cards
                    $('#totalOrders').text('0');
                    $('#totalRevenue').text(currencySymbol + '0');
                    $('#avgOrderValue').text(currencySymbol + '0');
                    $('#completedOrders').text('0');
                }
                showLoading(false);
            }).fail(function(xhr, status, error) {
                console.error('Failed to load summary data:', {xhr: xhr.responseText, status: status, error: error});
                // Set fallback data for summary cards
                $('#totalOrders').text('0');
                $('#totalRevenue').text(currencySymbol + '0');
                $('#avgOrderValue').text(currencySymbol + '0');
                $('#completedOrders').text('0');
                showLoading(false);
            });
        }

        function loadTableData() {
            const tableFilters = {
                ...currentFilters,
                report_type: 'detailed',
                per_page: $('#perPage').val() || 15,
                page: currentPage
            };

            $.get('/reports/sales-data', tableFilters, function(response) {
                if (response.success) {
                    updateSalesTable(response.data);
                } else {
                    console.error('Table data error:', response.message);
                    $('#salesTableBody').html('<tr><td colspan="8" class="text-center">No sales data available</td></tr>');
                }
                showLoading(false);
            }).fail(function(xhr, status, error) {
                console.error('Failed to load table data:', {xhr: xhr.responseText, status: status, error: error});
                $('#salesTableBody').html('<tr><td colspan="8" class="text-center">Failed to load sales data</td></tr>');
                showError('Failed to load table data');
                showLoading(false);
            });
        }

        function loadTopCourses(topCourses) {
            let html = '';
            if (topCourses && Array.isArray(topCourses) && topCourses.length > 0) {
                topCourses.forEach((course, index) => {
                    const courseTitle = course.course?.title || 'Unknown Course';
                    const totalSales = course.total_sales || 0;
                    const totalOrders = course.total_orders || 0;

                    html += `
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <div>
                                <span class="badge badge-primary">#${index + 1}</span>
                                <strong class="ml-2">${courseTitle}</strong>
                            </div>
                            <div class="text-right">
                                <div>${currencySymbol}${totalSales.toLocaleString()}</div>
                                <small class="text-muted">${totalOrders} orders</small>
                            </div>
                        </div>
                    `;
                });
            } else {
                html = '<div class="text-center text-muted"> {{ __('No course data available') }} </div>';
            }
            $('#topCoursesList').html(html);
        }

        function loadPaymentMethodsChart(paymentMethods) {
            const ctx = document.getElementById('paymentMethodsChart').getContext('2d');

            if (paymentMethodsChart) {
                paymentMethodsChart.destroy();
            }

            // Handle empty or undefined payment methods
            if (!paymentMethods || Object.keys(paymentMethods).length === 0) {
                paymentMethods = { 'No Data': 1 };
            }

            const labels = Object.keys(paymentMethods);
            const data = Object.values(paymentMethods);

            paymentMethodsChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels.map(label => label.charAt(0).toUpperCase() + label.slice(1)),
                    datasets: [{
                        data: data,
                        backgroundColor: [
                            '#6777ef', '#fc544b', '#ffa426', '#3abaf4', '#1abc9c'
                        ]
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

        function updateSalesTable(tableData) {
            let html = '';

            // Handle both paginated (with .data property) and non-paginated (direct array) data
            const orders = Array.isArray(tableData) ? tableData : (tableData.data || []);

            if (orders.length === 0) {
                html = '<tr><td colspan="8" class="text-center"> {{ __('No sales data available') }} </td></tr>';
            } else {
                orders.forEach(order => {
                    const statusBadge = getStatusBadge(order.status);
                    const orderNumber = order.order_number || order.id || 'N/A';
                    const createdAt = order.created_at ? moment(order.created_at, moment.ISO_8601).format('DD MMM YYYY') : 'N/A';
                    const userName = order.user?.name || 'N/A';
                    const courseTitle = order.order_courses?.[0]?.course?.title || (order.order_courses?.length > 1 ? 'Multiple Courses' : 'N/A');
                    const finalPrice = order.final_price || 0;
                    const paymentMethod = order.payment_method || 'N/A';

                    html += `
                        <tr>
                            <td>#${orderNumber}</td>
                            <td>${createdAt}</td>
                            <td>${userName}</td>
                            <td>${courseTitle}</td>
                            <td>${currencySymbol}${finalPrice.toLocaleString()}</td>
                            <td><span class="badge badge-info">${paymentMethod}</span></td>
                            <td>${statusBadge}</td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="viewOrderDetails(${order.id})">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                });
            }

            $('#salesTableBody').html(html);

            // Update pagination if pagination data exists
            if (tableData && !Array.isArray(tableData) && tableData.total !== undefined) {
                updatePagination(tableData);
            }
        }

        function getStatusBadge(status) {
            const badges = {
                'pending': '<span class="badge badge-warning">{{ __('Pending') }}</span>',
                'completed': '<span class="badge badge-success">{{ __('Completed') }}</span>',
                'cancelled': '<span class="badge badge-danger">{{ __('Cancelled') }}</span>',
                'failed': '<span class="badge badge-dark">{{ __('Failed') }}</span>'
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

        function exportReport(format) {
            const exportFilters = {
                ...currentFilters,
                export_format: format
            };

            // Create a temporary form for file download
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/reports/sales/export';

            // Add CSRF token
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_token';
            csrfInput.value = '{{ csrf_token() }}';
            form.appendChild(csrfInput);

            Object.keys(exportFilters).forEach(key => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = exportFilters[key];
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        function viewOrderDetails(orderId) {
            // Redirect to order details page or open modal
            window.location.href = `/orders/${orderId}`;
        }

        function showLoading(show) {
            if (show) {
                $('#loading-state').show();
                $('#summary-section, #table-section, #top-courses-section').addClass('opacity-50');

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
                $('#summary-section, #table-section, #top-courses-section').removeClass('opacity-50');
            }
        }

        function showError(message) {
            // You can implement a toast notification or alert here
            alert(message);
        }
    </script>
@endpush

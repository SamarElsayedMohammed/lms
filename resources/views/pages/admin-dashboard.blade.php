@extends('layouts.app')

@section('title', 'Admin Dashboard')

@push('style')
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="{{ asset('library/jqvmap/dist/jqvmap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('library/summernote/dist/summernote-bs4.min.css') }}">
    <link rel="stylesheet" href="{{ asset('library/chocolat/dist/css/chocolat.css') }}">

    <style>
        .chart-container {
            position: relative;
            min-height: 250px;
        }
        @media (max-width: 575.98px) {
            .chart-container { min-height: 200px; }
        }
    </style>
@endpush

@section('main')
<section class="section">
        <div class="section-header">
            <h1> {{ __('Admin Dashboard') }} </h1>
            <div class="section-header-button">
                <button class="btn btn-primary" onclick="refreshDashboard()">
                    <i class="fas fa-sync-alt"></i> {{ __('Refresh Data') }} </button>
            </div>
        </div>

        <!-- Loading State -->
        <div id="loading-state" class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only"> {{ __('Loading...') }} </span>
            </div>
            <p class="mt-2 text-muted"> {{ __('Loading comprehensive dashboard data...') }} </p>
        </div>
        
        <!-- Main Dashboard Content -->
        <div id="dashboard-content" style="display: none;">
            
            <!-- Overview Statistics Cards -->
            <div class="row">
                <div class="col-lg-3 col-md-6 col-sm-6 col-12 mb-4">
                    <x-stat-card
                        icon="fas fa-users"
                        color="primary"
                        :title="__('Total Users')"
                        title-id="total-users-label"
                        value-id="total-users-count"
                        growth-id="total-users-growth"
                        :growth-label="__('from last month')"
                    />
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12 mb-4">
                    <x-stat-card
                        icon="fas fa-graduation-cap"
                        color="success"
                        :title="__('Total Courses')"
                        title-id="total-courses-label"
                        value-id="total-courses-count"
                        growth-id="total-courses-growth"
                        :growth-label="__('from last month')"
                    />
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12 mb-4">
                    <x-stat-card
                        icon="fas fa-rupee-sign"
                        color="warning"
                        :title="__('Total Earnings')"
                        title-id="total-earnings-label"
                        value-id="total-earnings-count"
                        growth-id="total-earnings-growth"
                        :growth-label="__('from last month')"
                    />
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12 mb-4">
                    <x-stat-card
                        icon="fas fa-user-graduate"
                        color="info"
                        :title="__('Total Enrollments')"
                        title-id="total-enrollments-label"
                        value-id="total-enrollments-count"
                        growth-id="total-enrollments-growth"
                        :growth-label="__('from last month')"
                    />
                </div>
            </div>

            <!-- Additional Statistics Cards -->
            <div class="row">
                <div class="col-lg-3 col-md-6 col-sm-6 col-12 mb-4">
                    <x-stat-card
                        icon="fas fa-chalkboard-teacher"
                        color="info"
                        :title="__('Total Instructors')"
                        title-id="total-instructors-label"
                        value-id="total-instructors-count"
                    />
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12 mb-4">
                    <x-stat-card
                        icon="fas fa-play-circle"
                        color="success"
                        :title="__('Active Courses')"
                        title-id="active-courses-label"
                        value-id="active-courses-count"
                    />
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12 mb-4">
                    <x-stat-card
                        icon="fas fa-clock"
                        color="danger"
                        :title="__('Pending Approvals')"
                        title-id="pending-approvals-label"
                        value-id="pending-approvals-count"
                    />
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12 mb-4">
                    <x-stat-card
                        icon="fas fa-tags"
                        color="secondary"
                        :title="__('Total Categories')"
                        title-id="total-categories-label"
                        value-id="total-categories-count"
                    />
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row">
                <div class="col-lg-8 col-md-12 col-12 col-sm-12">
                    <div class="card">
                        <div class="card-header">
                            <h4> {{ __('Revenue & Enrollment Trends') }} </h4>
                            <div class="card-header-action">
                                <div class="btn-group">
                                    <button class="btn btn-primary" onclick="switchChart('revenue')"> {{ __('Revenue') }} </button>
                                    <button class="btn" onclick="switchChart('enrollment')"> {{ __('Enrollment') }} </button>
                                    <button class="btn" onclick="switchChart('courses')"> {{ __('Courses') }} </button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="mainChart"></canvas>
                            </div>
                            <div class="statistic-details mt-sm-4">
                                <div class="statistic-details-item">
                                    <div class="detail-value" id="chart-info"> {{ __('Monthly Revenue Trend (Last 12 Months)') }} </div>
                                    <div class="detail-name"> {{ __('Chart shows monthly data trends') }} </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-12 col-12 col-sm-12">
                    <div class="card">
                        <div class="card-header">
                            <h4> {{ __('Recent Activities') }} </h4>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled list-unstyled-border" id="recent-activities-list">
                                <!-- Dynamic content will be loaded here -->
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ratings Statistics Row -->
            <div class="row">
                <div class="col-lg-12 col-md-12 col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>{{ __('Ratings & Reviews Overview') }}</h4>
                            @can('ratings-list')
                            <div class="card-header-action">
                                <a href="{{ route('admin.ratings.index') }}" class="btn btn-primary btn-sm">
                                    <i class="fas fa-star"></i> {{ __('Manage Ratings') }}
                                </a>
                            </div>
                            @endcan
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-2">
                                    <div class="text-center">
                                        <div class="h4 mb-0 text-primary" id="total-ratings">{{ __('0') }}</div>
                                        <div class="text-muted">{{ __('Total Ratings') }}</div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="text-center">
                                        <div class="h4 mb-0 text-success" id="overall-average-rating">{{ __('0.0') }}</div>
                                        <div class="text-muted">{{ __('Overall Average') }}</div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="text-center">
                                        <div class="h4 mb-0 text-info" id="course-ratings-count">{{ __('0') }}</div>
                                        <div class="text-muted">{{ __('Course Ratings') }}</div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="text-center">
                                        <div class="h4 mb-0 text-warning" id="instructor-ratings-count">{{ __('0') }}</div>
                                        <div class="text-muted">{{ __('Instructor Ratings') }}</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <h6 class="mb-2">{{ __('Rating Breakdown') }}</h6>
                                        <div class="row text-center">
                                            <div class="col-2">
                                                <small>5★</small><br>
                                                <strong id="rating-5-stars">{{ __('0') }}</strong>
                                            </div>
                                            <div class="col-2">
                                                <small>4★</small><br>
                                                <strong id="rating-4-stars">{{ __('0') }}</strong>
                                            </div>
                                            <div class="col-2">
                                                <small>3★</small><br>
                                                <strong id="rating-3-stars">{{ __('0') }}</strong>
                                            </div>
                                            <div class="col-2">
                                                <small>2★</small><br>
                                                <strong id="rating-2-stars">{{ __('0') }}</strong>
                                            </div>
                                            <div class="col-2">
                                                <small>1★</small><br>
                                                <strong id="rating-1-star">{{ __('0') }}</strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Tables Row -->
            <div class="row">
                <div class="col-lg-6 col-md-12 col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4> {{ __('Course Statistics') }} </h4>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="h4 mb-0" id="published-courses"> {{ __('0') }} </div>
                                        <div class="text-muted"> {{ __('Published') }} </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="h4 mb-0" id="draft-courses"> {{ __('0') }} </div>
                                        <div class="text-muted"> {{ __('Draft') }} </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="h4 mb-0" id="total-lectures"> {{ __('0') }} </div>
                                        <div class="text-muted"> {{ __('Total Lectures') }} </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="h4 mb-0" id="total-quizzes"> {{ __('0') }} </div>
                                        <div class="text-muted"> {{ __('Total Quizzes') }} </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="h4 mb-0" id="average-rating"> {{ __('0.0') }} </div>
                                        <div class="text-muted"> {{ __('Avg Rating') }} </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="h4 mb-0" id="total-assignments"> {{ __('0') }} </div>
                                        <div class="text-muted"> {{ __('Assignments') }} </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 col-md-12 col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4> {{ __('User & Engagement Statistics') }} </h4>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="h4 mb-0" id="active-users"> {{ __('0') }} </div>
                                        <div class="text-muted"> {{ __('Active Users') }} </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="h4 mb-0" id="new-users-month"> {{ __('0') }} </div>
                                        <div class="text-muted"> {{ __('New This Month') }} </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="h4 mb-0" id="total-discussions"> {{ __('0') }} </div>
                                        <div class="text-muted"> {{ __('Discussions') }} </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="h4 mb-0" id="quiz-attempts"> {{ __('0') }} </div>
                                        <div class="text-muted"> {{ __('Quiz Attempts') }} </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="h4 mb-0" id="instructor-requests"> {{ __('0') }} </div>
                                        <div class="text-muted"> {{ __('Pending Instructors') }} </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center">
                                        <div class="h4 mb-0" id="helpdesk-questions"> {{ __('0') }} </div>
                                        <div class="text-muted"> {{ __('Support Tickets') }} </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Performers Row -->
            <div class="row">
                <div class="col-lg-6 col-md-12 col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4> {{ __('Top Instructors') }} </h4>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table-striped mb-0 table">
                                    <thead>
                                        <tr>
                                            <th> {{ __('Name') }} </th>
                                            <th> {{ __('Courses') }} </th>
                                            <th> {{ __('Status') }} </th>
                                        </tr>
                                    </thead>
                                    <tbody id="top-instructors-table">
                                        <!-- Dynamic content -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 col-md-12 col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4> {{ __('Most Popular Courses') }} </h4>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table-striped mb-0 table">
                                    <thead>
                                        <tr>
                                            <th> {{ __('Course Title') }} </th>
                                            <th> {{ __('Enrollments') }} </th>
                                            <th> {{ __('Instructor') }} </th>
                                        </tr>
                                    </thead>
                                    <tbody id="popular-courses-table">
                                        <!-- Dynamic content -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial Stats Row -->
            <div class="row">
                <div class="col-lg-4 col-md-6 col-12">
                    <div class="card card-statistic-2">
                        <div class="card-stats">
                            <div class="card-stats-title"> {{ __('Monthly Revenue') }} </div>
                            <div class="card-stats-items">
                                <div class="card-stats-item">
                                    <div class="card-stats-item-count" id="current-month-revenue"> {{ __('?0') }} </div>
                                    <div class="card-stats-item-label"> {{ __('This Month') }} </div>
                                </div>
                                <div class="card-stats-item">
                                    <div class="card-stats-item-count" id="previous-month-revenue"> {{ __('?0') }} </div>
                                    <div class="card-stats-item-label"> {{ __('Last Month') }} </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-icon shadow-primary bg-primary">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Revenue Growth') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="revenue-growth"> {{ __('0%') }} </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 col-12">
                    <div class="card card-statistic-2">
                        <div class="card-chart">
                            <canvas id="payment-methods-chart" height="80"></canvas>
                        </div>
                        <div class="card-icon shadow-primary bg-primary">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4> {{ __('Payment Methods') }} </h4>
                            </div>
                            <div class="card-body">
                                <span id="avg-order-value"> {{ __('?0') }} </span>
                                <div class="text-small text-muted"> {{ __('Avg Order Value') }} </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-12 col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4> {{ __('Categories by Courses') }} </h4>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive" style="max-height: 300px;">
                                <table class="table-striped mb-0 table">
                                    <thead>
                                        <tr>
                                            <th> {{ __('Category') }} </th>
                                            <th> {{ __('Courses') }} </th>
                                        </tr>
                                    </thead>
                                    <tbody id="categories-table">
                                        <!-- Dynamic content -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section> @endsection

@push('scripts')
<!-- JS Libraries -->
    <script src="{{ asset('library/simpleweather/jquery.simpleWeather.min.js') }}"></script>
    <script src="{{ asset('library/chart.js/dist/Chart.min.js') }}"></script>
    <script src="{{ asset('library/jqvmap/dist/jquery.vmap.min.js') }}"></script>
    <script src="{{ asset('library/jqvmap/dist/maps/jquery.vmap.world.js') }}"></script>
    <script src="{{ asset('library/summernote/dist/summernote-bs4.min.js') }}"></script>
    <script src="{{ asset('library/chocolat/dist/js/jquery.chocolat.min.js') }}"></script>

    <!-- Admin Dashboard Script -->
    <script>
        // Global variables for charts and data
        let mainChart;
        let paymentMethodsChart;
        let dashboardData = {};
        let currentChartType = 'revenue';
        
        // Translation strings
        const translations = {
            noDataAvailable: '{{ __("No data available") }}',
            approved: '{{ __("Approved") }}',
            pending: '{{ __("Pending") }}',
            rejected: '{{ __("Rejected") }}',
            unknown: '{{ __("Unknown") }}'
        };
        
        // Load dashboard data when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadDashboardData();
        });
        
        // Function to load comprehensive dashboard data from API
        async function loadDashboardData() {
            try {
                // Show loading state
                document.getElementById('loading-state').style.display = 'block';
                document.getElementById('dashboard-content').style.display = 'none';
                
                const response = await fetch('/api/dashboard-data', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Non-JSON response received:', text.substring(0, 200));
                    throw new Error('Server returned non-JSON response. Status: ' + response.status);
                }
                
                const result = await response.json();
                
                if (result.status) {
                    dashboardData = result.data;
                    updateDashboardUI();
                    
                    // Hide loading state and show content
                    document.getElementById('loading-state').style.display = 'none';
                    document.getElementById('dashboard-content').style.display = 'block';
                } else {
                    console.error('Failed to load dashboard data:', result.message);
                    showErrorState(result.message);
                }
            } catch (error) {
                console.error('Error loading dashboard data:', error);
                showErrorState(error.message || 'Failed to load dashboard data. Please refresh the page.');
            }
        }
        
        // Function to update the entire dashboard UI with dynamic data
        function updateDashboardUI() {
            updateOverviewStats();
            updateAdditionalStats();
            updateCourseStats();
            updateRatingsStats();
            updateUserEngagementStats();
            updateFinancialStats();
            updateCharts();
            updateRecentActivities();
            updateTopPerformers();
        }
        
        // Update overview statistics cards
        function updateOverviewStats() {
            if (dashboardData.overview_stats) {
                const stats = dashboardData.overview_stats;
                
                // Update main stat cards
                updateStatCard('total-users', stats.total_users);
                updateStatCard('total-courses', stats.total_courses);
                updateStatCard('total-earnings', stats.total_earnings);
                updateStatCard('total-enrollments', stats.total_enrollments);
            }
        }
        
        // Update additional statistics cards
        function updateAdditionalStats() {
            if (dashboardData.overview_stats) {
                const stats = dashboardData.overview_stats;
                
                updateSimpleStatCard('total-instructors', stats.total_instructors);
                updateSimpleStatCard('active-courses', stats.active_courses);
                updateSimpleStatCard('pending-approvals', stats.pending_approvals);
                updateSimpleStatCard('total-categories', stats.total_categories);
            }
        }
        
        // Update course statistics
        function updateCourseStats() {
            if (dashboardData.course_stats) {
                const stats = dashboardData.course_stats;
                
                document.getElementById('published-courses').textContent = stats.course_status?.published || 0;
                document.getElementById('draft-courses').textContent = stats.course_status?.draft || 0;
                document.getElementById('total-lectures').textContent = stats.content_stats?.total_lectures || 0;
                document.getElementById('total-quizzes').textContent = stats.content_stats?.total_quizzes || 0;
                document.getElementById('average-rating').textContent = stats.rating_stats?.course_ratings?.average || '0.0';
                document.getElementById('total-assignments').textContent = stats.content_stats?.total_assignments || 0;
            }
        }
        
        // Update ratings statistics
        function updateRatingsStats() {
            if (dashboardData.course_stats && dashboardData.course_stats.rating_stats) {
                const ratingStats = dashboardData.course_stats.rating_stats;
                
                // Update main ratings stats
                document.getElementById('total-ratings').textContent = ratingStats.total_ratings || 0;
                document.getElementById('overall-average-rating').textContent = ratingStats.overall_average || '0.0';
                document.getElementById('course-ratings-count').textContent = ratingStats.course_ratings?.total || 0;
                document.getElementById('instructor-ratings-count').textContent = ratingStats.instructor_ratings?.total || 0;
                
                // Update rating breakdown
                if (ratingStats.rating_breakdown) {
                    document.getElementById('rating-5-stars').textContent = ratingStats.rating_breakdown['5_stars'] || 0;
                    document.getElementById('rating-4-stars').textContent = ratingStats.rating_breakdown['4_stars'] || 0;
                    document.getElementById('rating-3-stars').textContent = ratingStats.rating_breakdown['3_stars'] || 0;
                    document.getElementById('rating-2-stars').textContent = ratingStats.rating_breakdown['2_stars'] || 0;
                    document.getElementById('rating-1-star').textContent = ratingStats.rating_breakdown['1_star'] || 0;
                }
            }
        }
        
        // Update user and engagement statistics
        function updateUserEngagementStats() {
            if (dashboardData.user_stats && dashboardData.engagement_stats) {
                const userStats = dashboardData.user_stats;
                const engagementStats = dashboardData.engagement_stats;
                
                document.getElementById('active-users').textContent = userStats.user_activity?.active || 0;
                document.getElementById('new-users-month').textContent = userStats.user_activity?.new_this_month || 0;
                document.getElementById('total-discussions').textContent = engagementStats.discussion_stats?.total_discussions || 0;
                document.getElementById('quiz-attempts').textContent = engagementStats.assessment_stats?.total_quiz_attempts || 0;
                document.getElementById('instructor-requests').textContent = userStats.instructor_stats?.pending_requests || 0;
                document.getElementById('helpdesk-questions').textContent = engagementStats.support_stats?.helpdesk_questions || 0;
            }
        }
        
        // Update financial statistics
        function updateFinancialStats() {
            if (dashboardData.financial_stats) {
                const stats = dashboardData.financial_stats;
                const currencySymbol = dashboardData.currency_symbol || '$';
                
                // Format numbers with proper currency symbol
                const formatCurrency = (value) => {
                    return currencySymbol + parseFloat(value || 0).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                };
                
                document.getElementById('current-month-revenue').textContent = formatCurrency(stats.monthly_revenue?.current || 0);
                document.getElementById('previous-month-revenue').textContent = formatCurrency(stats.monthly_revenue?.previous || 0);
                document.getElementById('revenue-growth').textContent = (stats.monthly_revenue?.growth || 0) + '%';
                document.getElementById('avg-order-value').textContent = formatCurrency(stats.average_order_value || 0);
                
                // Update categories table
                updateCategoriesTable(dashboardData.course_stats?.course_by_category || []);
            }
        }
        
        // Update charts
        function updateCharts() {
            initializeMainChart();
            initializePaymentMethodsChart();
        }
        
        // Initialize main chart
        function initializeMainChart() {
            const ctx = document.getElementById('mainChart');
            if (!ctx) return;
            
            if (mainChart) {
                mainChart.destroy();
            }
            
            const chartData = getChartDataByType(currentChartType);
            
            mainChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        label: chartData.label,
                        data: chartData.data,
                        borderColor: '#6777ef',
                        backgroundColor: 'rgba(103, 119, 239, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        pointBackgroundColor: '#6777ef',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    aspectRatio: 3,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            },
                            ticks: {
                                maxTicksLimit: 6
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            },
                            ticks: {
                                maxTicksLimit: 8
                            }
                        }
                    },
                    elements: {
                        point: {
                            radius: 4,
                            hoverRadius: 6
                        }
                    }
                }
            });
        }
        
        // Initialize payment methods chart
        function initializePaymentMethodsChart() {
            const ctx = document.getElementById('payment-methods-chart');
            if (!ctx) return;
            
            if (paymentMethodsChart) {
                paymentMethodsChart.destroy();
            }
            
            const paymentData = dashboardData.financial_stats?.payment_methods || [];
            const labels = paymentData.map(item => item.method);
            const data = paymentData.map(item => item.count);
            
            paymentMethodsChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: ['#6777ef', '#28a745', '#ffc107', '#dc3545', '#17a2b8']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
        
        // Get chart data by type
        function getChartDataByType(type) {
            const charts = dashboardData.monthly_charts || {};
            
            switch (type) {
                case 'revenue':
                    return {
                        labels: charts.revenue_chart?.map(item => item.month) || [],
                        data: charts.revenue_chart?.map(item => item.revenue) || [],
                        label: 'Revenue'
                    };
                case 'enrollment':
                    return {
                        labels: charts.course_enrollment_chart?.map(item => item.month) || [],
                        data: charts.course_enrollment_chart?.map(item => item.enrollments) || [],
                        label: 'Enrollments'
                    };
                case 'courses':
                    return {
                        labels: charts.course_creation_chart?.map(item => item.month) || [],
                        data: charts.course_creation_chart?.map(item => item.courses) || [],
                        label: 'New Courses'
                    };
                default:
                    return { labels: [], data: [], label: 'Data' };
            }
        }
        
        // Switch chart type
        function switchChart(type) {
            currentChartType = type;
            
            // Update button states
            document.querySelectorAll('.btn-group .btn').forEach(btn => {
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-secondary');
            });
            event.target.classList.remove('btn-secondary');
            event.target.classList.add('btn-primary');
            
            // Update chart info
            const chartInfoMap = {
                'revenue': 'Monthly Revenue Trend (Last 12 Months)',
                'enrollment': 'Monthly Enrollment Trend (Last 12 Months)',
                'courses': 'Monthly Course Creation Trend (Last 12 Months)'
            };
            document.getElementById('chart-info').textContent = chartInfoMap[type] || 'Chart Data';
            
            // Refresh chart
            initializeMainChart();
        }
        
        // Update recent activities
        function updateRecentActivities() {
            const activities = dashboardData.recent_activities || [];
            const activitiesList = document.getElementById('recent-activities-list');
            
            if (activitiesList) {
                let html = '';
                activities.forEach(activity => {
                    html += `
                        <li class="media">
                            <div class="media-icon bg-${activity.color}">
                                <i class="${activity.icon}"></i>
                            </div>
                            <div class="media-body">
                                <div class="text-${activity.color} float-right">${activity.time}</div>
                                <div class="media-title">${activity.title}</div>
                                <span class="text-small text-muted">${activity.description}</span>
                            </div>
                        </li>
                    `;
                });
                activitiesList.innerHTML = html;
            }
        }
        
        // Update top performers
        function updateTopPerformers() {
            updateTopInstructorsTable();
            updatePopularCoursesTable();
        }
        
        // Update top instructors table
        function updateTopInstructorsTable() {
            const instructors = dashboardData.top_performers?.top_instructors || [];
            const table = document.getElementById('top-instructors-table');
            
            if (table) {
                let html = '';
                instructors.forEach(instructor => {
                    const statusBadge = getStatusBadge(instructor.status);
                    html += `
                        <tr>
                            <td>${instructor.name}</td>
                            <td>${instructor.total_courses}</td>
                            <td>${statusBadge}</td>
                        </tr>
                    `;
                });
                table.innerHTML = html || '<tr><td colspan="3" class="text-center">' + translations.noDataAvailable + '</td></tr>';
            }
        }
        
        // Update popular courses table
        function updatePopularCoursesTable() {
            const courses = dashboardData.top_performers?.top_courses || [];
            const table = document.getElementById('popular-courses-table');
            
            if (table) {
                let html = '';
                courses.forEach(course => {
                    html += `
                        <tr>
                            <td>${course.title}</td>
                            <td>${course.enrollments}</td>
                            <td>${course.instructor}</td>
                        </tr>
                    `;
                });
                table.innerHTML = html || '<tr><td colspan="3" class="text-center">' + translations.noDataAvailable + '</td></tr>';
            }
        }
        
        // Update categories table
        function updateCategoriesTable(categories) {
            const table = document.getElementById('categories-table');
            
            if (table) {
                let html = '';
                categories.forEach(category => {
                    html += `
                        <tr>
                            <td>${category.category}</td>
                            <td>${category.count}</td>
                        </tr>
                    `;
                });
                table.innerHTML = html || '<tr><td colspan="2" class="text-center">' + translations.noDataAvailable + '</td></tr>';
            }
        }
        
        // Helper function to update stat card with growth
        function updateStatCard(prefix, data) {
            if (data) {
                document.getElementById(prefix + '-count').textContent = data.count;
                document.getElementById(prefix + '-label').textContent = data.label;

                const growthElement = document.getElementById(prefix + '-growth');
                if (growthElement && data.growth !== undefined) {
                    growthElement.textContent = (data.growth > 0 ? '+' : '') + data.growth + '%';
                    growthElement.classList.remove('positive', 'negative', 'text-success', 'text-danger');
                    growthElement.classList.add(data.growth >= 0 ? 'positive' : 'negative');
                }
            }
        }
        
        // Helper function to update simple stat card
        function updateSimpleStatCard(prefix, data) {
            if (data) {
                document.getElementById(prefix + '-count').textContent = data.count;
                document.getElementById(prefix + '-label').textContent = data.label;
            }
        }
        
        // Helper function to get status badge
        function getStatusBadge(status) {
            const badges = {
                'approved': '<span class="badge badge-success">' + translations.approved + '</span>',
                'pending': '<span class="badge badge-warning">' + translations.pending + '</span>',
                'rejected': '<span class="badge badge-danger">' + translations.rejected + '</span>'
            };
            return badges[status] || '<span class="badge badge-secondary">' + translations.unknown + '</span>';
        }
        
        // Show error state
        function showErrorState(message) {
            document.getElementById('loading-state').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Failed to load dashboard data: ${message}
                </div>
                <button class="btn btn-primary" onclick="loadDashboardData()">
                    <i class="fas fa-sync-alt"></i> {{ __('Retry') }} </button>
            `;
        }
        
        // Refresh dashboard data
        function refreshDashboard() {
            loadDashboardData();
        }
        
        // Make functions globally available
        window.refreshDashboard = refreshDashboard;
        window.switchChart = switchChart;
    </script>
@endpush

@extends('layouts.app')

@php
    use Illuminate\Support\Str;
@endphp

@section('title')
    {{ __('Progress Tracking') }}
@endsection

@push('style')
    <link rel="stylesheet" href="{{ asset('library/datatables/media/css/jquery.dataTables.min.css') }}">
    <link rel="stylesheet" href="{{ asset('library/select2/dist/css/select2.min.css') }}">
@endpush

@section('main')
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-chart-line"></i> {{ __('Progress Tracking') }}</h1>
            <div class="section-header-button">
                <button class="btn btn-primary" onclick="refreshData()">
                    <i class="fas fa-sync-alt"></i> {{ __('Refresh') }}
                </button>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-primary">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Enrollments') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ number_format($stats['total_enrollments']) }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-warning">
                        <i class="fas fa-play"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Not Started') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ number_format($stats['not_started']) }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-info">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('In Progress') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ number_format($stats['in_progress']) }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Completed') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ number_format($stats['completed']) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Average Progress -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-bar"></i> {{ __('Overall Progress') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h5>{{ __('Average Progress') }}</h5>
                                <div class="progress mb-3" style="height: 24px;">
                                    <div class="progress-bar bg-info" role="progressbar" style="width: {{ $stats['average_progress'] }}%" aria-valuenow="{{ $stats['average_progress'] }}" aria-valuemin="0" aria-valuemax="100">
                                        <span class="text-white font-weight-bold">{{ number_format($stats['average_progress'], 1) }}%</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5>{{ __('Progress Distribution') }}</h5>
                                <div class="row">
                                    <div class="col-4 text-center">
                                        <div class="text-warning">
                                            <i class="fas fa-play fa-2x"></i>
                                            <p class="mt-1">{{ $stats['not_started'] }}</p>
                                            <small>{{ __('Not Started') }}</small>
                                        </div>
                                    </div>
                                    <div class="col-4 text-center">
                                        <div class="text-info">
                                            <i class="fas fa-clock fa-2x"></i>
                                            <p class="mt-1">{{ $stats['in_progress'] }}</p>
                                            <small>{{ __('In Progress') }}</small>
                                        </div>
                                    </div>
                                    <div class="col-4 text-center">
                                        <div class="text-success">
                                            <i class="fas fa-check-circle fa-2x"></i>
                                            <p class="mt-1">{{ $stats['completed'] }}</p>
                                            <small>{{ __('Completed') }}</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h4><i class="fas fa-filter"></i> {{ __('Filters') }}</h4>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('admin.tracking.index') }}">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2 col-sm-6">
                            <label class="form-label text-muted small mb-1">{{ __('Course') }}</label>
                            <select name="course_id" class="form-control select2">
                                <option value="">{{ __('All Courses') }}</option>
                                @foreach($courses as $course)
                                    <option value="{{ $course->id }}" {{ request('course_id') == $course->id ? 'selected' : '' }}>
                                        {{ $course->title }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <label class="form-label text-muted small mb-1">{{ __('Instructor') }}</label>
                            <select name="instructor_id" class="form-control select2">
                                <option value="">{{ __('All Instructors') }}</option>
                                @foreach($instructors as $instructor)
                                    <option value="{{ $instructor->id }}" {{ request('instructor_id') == $instructor->id ? 'selected' : '' }}>
                                        {{ $instructor->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <label class="form-label text-muted small mb-1">{{ __('Status') }}</label>
                            <select name="progress_status" class="form-control">
                                <option value="">{{ __('All Status') }}</option>
                                <option value="not_started" {{ request('progress_status') == 'not_started' ? 'selected' : '' }}>{{ __('Not Started') }}</option>
                                <option value="in_progress" {{ request('progress_status') == 'in_progress' ? 'selected' : '' }}>{{ __('In Progress') }}</option>
                                <option value="completed" {{ request('progress_status') == 'completed' ? 'selected' : '' }}>{{ __('Completed') }}</option>
                            </select>
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <label class="form-label text-muted small mb-1">{{ __('Date From') }}</label>
                            <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <label class="form-label text-muted small mb-1">{{ __('Date To') }}</label>
                            <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <label class="form-label text-muted small mb-1">{{ __('Search') }}</label>
                            <input type="text" name="search" class="form-control" placeholder="{{ __('Search...') }}" value="{{ request('search') }}">
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="d-flex w-100 gap-2 flex-wrap">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search mr-2"></i> {{ __('Apply Filter') }}
                                </button>
                                <a href="{{ route('admin.tracking.index') }}" class="btn btn-secondary">
                                    <i class="fas fa-sync-alt mr-2"></i> {{ __('Clear') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tracking Table -->
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-list"></i> {{ __('Progress Tracking') }}</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="trackingTable">
                        <thead class="thead-light">
                            <tr>
                                <th style="width: 20%;">{{ __('Student') }}</th>
                                <th style="width: 20%;">{{ __('Course') }}</th>
                                <th style="width: 12%;">{{ __('Instructor') }}</th>
                                <th style="width: 18%;">{{ __('Progress') }}</th>
                                <th style="width: 12%;">{{ __('Status') }}</th>
                                <th style="width: 10%;">{{ __('Enrollment Date') }}</th>
                                <th style="width: 8%;">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($trackings as $tracking)
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar avatar-sm bg-primary text-white mr-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; border-radius: 50%; font-weight: bold; flex-shrink: 0;">
                                                {{ substr($tracking->user->name ?? 'N/A', 0, 1) }}
                                            </div>
                                            <div>
                                                <strong class="d-block">{{ $tracking->user->name ?? 'N/A' }}</strong>
                                                <small class="text-muted">{{ $tracking->user->email ?? 'N/A' }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong>{{ $tracking->course->title ?? 'N/A' }}</strong>
                                    </td>
                                    <td>
                                        {{ $tracking->course->user->name ?? 'N/A' }}
                                    </td>
                                    <td>
                                        @php
                                            $progressPercentage = $tracking->progress_percentage ?? 0;
                                        @endphp
                                        <div class="progress mb-2" style="height: 24px;">
                                            <div class="progress-bar 
                                                @if($tracking->status === 'not_started') bg-secondary
                                                @elseif($tracking->status === 'in_progress') bg-info
                                                @else bg-primary
                                                @endif" 
                                                role="progressbar" 
                                                style="width: {{ $progressPercentage }}%" 
                                                aria-valuenow="{{ $progressPercentage }}" 
                                                aria-valuemin="0" 
                                                aria-valuemax="100">
                                                <span class="text-white font-weight-bold">{{ number_format($progressPercentage, 1) }}%</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge 
                                            @if($tracking->status === 'not_started') badge-warning
                                            @elseif($tracking->status === 'in_progress') badge-info
                                            @else badge-primary
                                            @endif">
                                            @if($tracking->status === 'not_started')
                                                {{ __('Not Started') }}
                                            @elseif($tracking->status === 'in_progress')
                                                {{ __('In Progress') }}
                                            @else
                                                {{ __('Completed') }}
                                            @endif
                                        </span>
                                    </td>
                                    <td>
                                        {{ $tracking->created_at ? $tracking->created_at->format('d M Y') : 'N/A' }}
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.tracking.show', $tracking->id) }}" class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i> {{ __('View Details') }}
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <div class="empty-state">
                                            <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                                            <h5>{{ __('No tracking data found') }}</h5>
                                            <p class="text-muted">{{ __('There are no enrollments matching your criteria.') }}</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                @if($trackings->hasPages())
                <div class="d-flex justify-content-center mt-4">
                    <nav aria-label="Page navigation">
                        <ul class="pagination pagination-md mb-0">
                            {{-- Previous Page Link --}}
                            @if ($trackings->onFirstPage())
                                <li class="page-item disabled">
                                    <span class="page-link" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; Previous</span>
                                    </span>
                                </li>
                            @else
                                <li class="page-item">
                                    <a class="page-link" href="{{ $trackings->appends(request()->query())->previousPageUrl() }}" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; Previous</span>
                                    </a>
                                </li>
                            @endif

                            {{-- Pagination Elements --}}
                            @php
                                $currentPage = $trackings->currentPage();
                                $lastPage = $trackings->lastPage();
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($lastPage, $currentPage + 2);
                                
                                // Show first page if not in range
                                if ($startPage > 1) {
                                    $showFirstPage = true;
                                } else {
                                    $showFirstPage = false;
                                }
                                
                                // Show last page if not in range
                                if ($endPage < $lastPage) {
                                    $showLastPage = true;
                                } else {
                                    $showLastPage = false;
                                }
                            @endphp
                            
                            {{-- First Page --}}
                            @if($showFirstPage)
                                <li class="page-item">
                                    <a class="page-link" href="{{ $trackings->appends(request()->query())->url(1) }}">1</a>
                                </li>
                                @if($startPage > 2)
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                @endif
                            @endif
                            
                            {{-- Page Numbers --}}
                            @for ($page = $startPage; $page <= $endPage; $page++)
                                @if ($page == $currentPage)
                                    <li class="page-item active">
                                        <span class="page-link">{{ $page }}</span>
                                    </li>
                                @else
                                    <li class="page-item">
                                        <a class="page-link" href="{{ $trackings->appends(request()->query())->url($page) }}">{{ $page }}</a>
                                    </li>
                                @endif
                            @endfor
                            
                            {{-- Last Page --}}
                            @if($showLastPage)
                                @if($endPage < $lastPage - 1)
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                @endif
                                <li class="page-item">
                                    <a class="page-link" href="{{ $trackings->appends(request()->query())->url($lastPage) }}">{{ $lastPage }}</a>
                                </li>
                            @endif

                            {{-- Next Page Link --}}
                            @if ($trackings->hasMorePages())
                                <li class="page-item">
                                    <a class="page-link" href="{{ $trackings->appends(request()->query())->nextPageUrl() }}" aria-label="Next">
                                        <span aria-hidden="true">Next &raquo;</span>
                                    </a>
                                </li>
                            @else
                                <li class="page-item disabled">
                                    <span class="page-link" aria-label="Next">
                                        <span aria-hidden="true">Next &raquo;</span>
                                    </span>
                                </li>
                            @endif
                        </ul>
                    </nav>
                </div>
                <div class="d-flex justify-content-center mt-2">
                    <p class="text-muted small">
                        Showing {{ $trackings->firstItem() }} to {{ $trackings->lastItem() }} of {{ $trackings->total() }} results
                    </p>
                </div>
                @endif
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('library/datatables/media/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ asset('library/select2/dist/js/select2.full.min.js') }}"></script>
    <script>
        $(document).ready(function() {
            $('.select2').select2({
                placeholder: 'Select an option',
                allowClear: true
            });

            $('#trackingTable').DataTable({
                "paging": false,
                "searching": false,
                "ordering": true,
                "info": false
            });
        });

        function refreshData() {
            location.reload();
        }
    </script>
    <style>
        #trackingTable tbody tr {
            vertical-align: middle;
        }
        #trackingTable td {
            padding: 1rem 0.75rem;
        }
        #trackingTable .progress {
            min-width: 100px;
        }
        .avatar-sm {
            font-size: 14px;
        }
        .empty-state {
            padding: 2rem;
        }
        .empty-state i {
            opacity: 0.5;
        }
        .table-hover tbody tr:hover {
            background-color: #f8f9fc;
        }
        .thead-light th {
            background-color: #f8f9fc;
            border-bottom: 2px solid #e3e6f0;
            font-weight: 600;
            color: #5a5c69;
            padding: 1rem 0.75rem;
        }
        .progress-bar {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
@endpush

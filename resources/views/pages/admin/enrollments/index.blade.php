@extends('layouts.app')

@section('title')
    {{ __('Enrollment Management') }}
@endsection

@push('style')
    <link rel="stylesheet" href="{{ asset('library/datatables/media/css/jquery.dataTables.min.css') }}">
    <link rel="stylesheet" href="{{ asset('library/select2/dist/css/select2.min.css') }}">
@endpush

@section('main')
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-graduation-cap"></i> {{ __('Enrollment Management') }}</h1>
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
                    <div class="card-icon bg-success">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Today Enrollments') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ number_format($stats['today_enrollments']) }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-info">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('This Month') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ number_format($stats['monthly_enrollments']) }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-warning">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Active Students') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ number_format($stats['active_students']) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-filter"></i> {{ __('Filters') }}</h4>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('admin.enrollments.index') }}">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>{{ __('Course') }}</label>
                                <select name="course_id" class="form-control select2">
                                    <option value="">{{ __('All Courses') }}</option>
                                    @foreach($courses as $course)
                                        <option value="{{ $course->id }}" {{ request('course_id') == $course->id ? 'selected' : '' }}>
                                            {{ $course->title }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>{{ __('Instructor') }}</label>
                                <select name="instructor_id" class="form-control select2">
                                    <option value="">{{ __('All Instructors') }}</option>
                                    @foreach($instructors as $instructor)
                                        <option value="{{ $instructor->id }}" {{ request('instructor_id') == $instructor->id ? 'selected' : '' }}>
                                            {{ $instructor->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>{{ __('Date From') }}</label>
                                <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>{{ __('Date To') }}</label>
                                <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ __('Search') }}</label>
                                <input type="text" name="search" class="form-control" placeholder="{{ __('Search by student name, email or course title') }}" value="{{ request('search') }}">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i> {{ __('Apply Filters') }}
                                    </button>
                                   
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Enrollments Table -->
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-list"></i> {{ __('Enrollments List') }}</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped" id="enrollmentsTable">
                        <thead>
                            <tr>
                                <th>{{ __('Student') }}</th>
                                <th>{{ __('Course') }}</th>
                                <th>{{ __('Instructor') }}</th>
                                <th>{{ __('Enrollment Date') }}</th>
                                <th>{{ __('Price') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($enrollments as $enrollment)
                                <tr>
                                    <td>
                                        <div>
                                            <strong>{{ $enrollment->order->user->name ?? 'N/A' }}</strong><br>
                                            <small class="text-muted">{{ $enrollment->order->user->email ?? 'N/A' }}</small>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <strong>{{ $enrollment->course->title ?? 'N/A' }}</strong><br>
                                            <small class="text-muted">{{ \Illuminate\Support\Str::limit($enrollment->course->description ?? '', 50) }}</small>
                                        </div>
                                    </td>
                                    <td>
                                        {{ $enrollment->course->user->name ?? 'N/A' }}
                                    </td>
                                    <td>
                                        {{ $enrollment->created_at->format('d M Y, H:i') }}
                                    </td>
                                    <td>
                                        <strong>₹{{ number_format($enrollment->price) }}</strong>
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.enrollments.show', $enrollment->id) }}" class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye"></i> {{ __('View Details') }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                @if($enrollments->hasPages())
                <div class="d-flex justify-content-between align-items-center mt-4">
                    <div class="text-muted">
                        {{ __('Showing') }} {{ $enrollments->firstItem() ?? 0 }} {{ __('to') }} {{ $enrollments->lastItem() ?? 0 }} {{ __('of') }} {{ $enrollments->total() }} {{ __('results') }}
                    </div>
                    <nav aria-label="Page navigation">
                        <ul class="pagination mb-0">
                            <!-- Previous Button -->
                            <li class="page-item {{ $enrollments->onFirstPage() ? 'disabled' : '' }}">
                                <a class="page-link" href="{{ $enrollments->appends(request()->query())->previousPageUrl() }}" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>

                            <!-- Page Numbers -->
                            @php
                                $currentPage = $enrollments->currentPage();
                                $lastPage = $enrollments->lastPage();
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($lastPage, $currentPage + 2);
                            @endphp

                            @if($startPage > 1)
                                <li class="page-item">
                                    <a class="page-link" href="{{ $enrollments->appends(request()->query())->url(1) }}">1</a>
                                </li>
                                @if($startPage > 2)
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                @endif
                            @endif

                            @for($i = $startPage; $i <= $endPage; $i++)
                                <li class="page-item {{ $i == $currentPage ? 'active' : '' }}">
                                    <a class="page-link" href="{{ $enrollments->appends(request()->query())->url($i) }}">{{ $i }}</a>
                                </li>
                            @endfor

                            @if($endPage < $lastPage)
                                @if($endPage < $lastPage - 1)
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                @endif
                                <li class="page-item">
                                    <a class="page-link" href="{{ $enrollments->appends(request()->query())->url($lastPage) }}">{{ $lastPage }}</a>
                                </li>
                            @endif

                            <!-- Next Button -->
                            <li class="page-item {{ !$enrollments->hasMorePages() ? 'disabled' : '' }}">
                                <a class="page-link" href="{{ $enrollments->appends(request()->query())->nextPageUrl() }}" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                @else
                <div class="d-flex justify-content-center mt-4">
                    <div class="text-muted">
                        {{ __('Showing') }} {{ $enrollments->count() }} {{ __('results') }}
                    </div>
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

            $('#enrollmentsTable').DataTable({
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
@endpush

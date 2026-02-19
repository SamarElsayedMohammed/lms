@extends('layouts.app')

@section('title')
    Assignment Statistics
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
@endsection

@section('main')
<section class="section">
    <div class="section-body">
            <!-- Filter Section -->
            <div class="card">
                <div class="card-header">
                    <h4>Filters</h4>
                </div>
                <div class="card-body">
                    <form method="GET" action="{{ route('admin.assignments.statistics') }}">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4 col-sm-6">
                                <label class="form-label text-muted small mb-1">Course</label>
                                <select name="course_id" class="form-control">
                                    <option value="">All Courses</option>
                                    @foreach($courses as $course)
                                        <option value="{{ $course->id }}" {{ request('course_id') == $course->id ? 'selected' : '' }}>
                                            {{ $course->title }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 col-sm-6">
                                <label class="form-label text-muted small mb-1">Date From</label>
                                <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <label class="form-label text-muted small mb-1">Date To</label>
                                <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                            </div>
                            <div class="col-md-1 col-sm-6 d-flex justify-content-end">
                                <div class="d-flex w-100 gap-2 flex-wrap">
                                    <button type="submit" class="btn btn-primary flex-fill">
                                        <i class="fas fa-search mr-2"></i>Apply
                                    </button>
                                    <a href="{{ route('admin.assignments.statistics') }}" class="btn btn-secondary flex-fill">
                                        <i class="fas fa-sync-alt mr-2"></i>Clear
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Statistics Overview -->
            <div class="row">
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-primary">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>Total Submissions</h4>
                            </div>
                            <div class="card-body">
                                {{ $statistics['overview']['total_submissions'] }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-warning">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>Pending Review</h4>
                            </div>
                            <div class="card-body">
                                {{ $statistics['overview']['pending_submissions'] }}
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
                                <h4>Accepted</h4>
                            </div>
                            <div class="card-body">
                                {{ $statistics['overview']['accepted_submissions'] }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-danger">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>Rejected</h4>
                            </div>
                            <div class="card-body">
                                {{ $statistics['overview']['rejected_submissions'] }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Acceptance Rate -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Acceptance Rate</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="text-center">
                                        <h2 class="text-primary">{{ $statistics['overview']['acceptance_rate'] }}%</h2>
                                        <p class="text-muted">Overall Acceptance Rate</p>
                                    </div>
                               
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-primary" role="progressbar" 
                                             style="width: {{ $statistics['overview']['acceptance_rate'] }}%" 
                                             aria-valuenow="{{ $statistics['overview']['acceptance_rate'] }}" 
                                             aria-valuemin="0" aria-valuemax="100">
                                            {{ $statistics['overview']['acceptance_rate'] }}%
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Course Breakdown -->
            @if($statistics['course_breakdown']->count() > 0)
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h4>Submissions by Course</h4>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Course</th>
                                                <th>Submissions</th>
                                                <th>Percentage</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($statistics['course_breakdown'] as $course)
                                                <tr>
                                                    <td>{{ $course->course_name }}</td>
                                                    <td>{{ $course->count }}</td>
                                                    <td>
                                                        @php
                                                            $percentage = $statistics['overview']['total_submissions'] > 0 
                                                                ? round(($course->count / $statistics['overview']['total_submissions']) * 100, 2) 
                                                                : 0;
                                                        @endphp
                                                        {{ $percentage }}%
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Recent Submissions -->
            @if($statistics['recent_submissions']->count() > 0)
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h4>Recent Submissions</h4>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                <th>Course</th>
                                                <th>Status</th>
                                                <th>Submitted</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($statistics['recent_submissions'] as $submission)
                                                <tr>
                                                    <td>{{ $submission->user->name }}</td>
                                                    <td>{{ $submission->assignment->chapter->course->title }}</td>
                                                    <td>
                                                        @if($submission->status == 'submitted')
                                                            <span class="badge badge-warning">Pending</span>
                                                        @elseif($submission->status == 'accepted')
                                                            <span class="badge badge-success">Accepted</span>
                                                        @elseif($submission->status == 'rejected')
                                                            <span class="badge badge-danger">Rejected</span>
                                                        @endif
                                                    </td>
                                                    <td>{{ $submission->created_at->format('M d, Y H:i') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
    </div>
</section>
@endsection

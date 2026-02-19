@extends('layouts.app')

@php
    use Illuminate\Support\Str;
@endphp

@section('title')
    {{ __('Progress Tracking Details') }}
@endsection

@push('style')
    <link rel="stylesheet" href="{{ asset('library/datatables/media/css/jquery.dataTables.min.css') }}">
    <link rel="stylesheet" href="{{ asset('library/select2/dist/css/select2.min.css') }}">
@endpush

@section('main')
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-chart-line"></i> {{ __('Progress Tracking Details') }}</h1>
            <div class="section-header-button">
                <a href="{{ route('admin.tracking.index') }}" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> {{ __('Back to List') }}
                </a>
            </div>
        </div>

        <!-- Student and Course Information -->
        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-user"></i> {{ __('Student Information') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-sm-4"><strong>{{ __('Name') }}:</strong></div>
                            <div class="col-sm-8">{{ $tracking->user->name ?? 'N/A' }}</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4"><strong>{{ __('Email') }}:</strong></div>
                            <div class="col-sm-8">{{ $tracking->user->email ?? 'N/A' }}</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4"><strong>{{ __('Enrollment Date') }}:</strong></div>
                            <div class="col-sm-8">{{ $tracking->created_at->format('d M Y, H:i') }}</div>
                        </div>
                        @if($tracking->completed_at)
                        <div class="row">
                            <div class="col-sm-4"><strong>{{ __('Completed Date') }}:</strong></div>
                            <div class="col-sm-8">{{ $tracking->completed_at->format('d M Y, H:i') }}</div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-book"></i> {{ __('Course Information') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-sm-4"><strong>{{ __('Course Title') }}:</strong></div>
                            <div class="col-sm-8">{{ $tracking->course->title ?? 'N/A' }}</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4"><strong>{{ __('Instructor') }}:</strong></div>
                            <div class="col-sm-8">{{ $tracking->course->user->name ?? 'N/A' }}</div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4"><strong>{{ __('Total Chapters') }}:</strong></div>
                            <div class="col-sm-8">{{ $progressData['total_chapters'] }}</div>
                        </div>
                                    <div class="row">
                                        <div class="col-sm-4"><strong>{{ __('Total Lectures') }}:</strong></div>
                                        <div class="col-sm-8">{{ $progressData['total_lectures'] }}</div>
                                    </div>
                                    <div class="row">
                                        <div class="col-sm-4"><strong>{{ __('Total Curriculum Items') }}:</strong></div>
                                        <div class="col-sm-8">{{ $progressData['total_items'] ?? 0 }} ({{ __('Lectures, Quizzes, Assignments, Resources') }})</div>
                                    </div>
                                    <div class="row">
                                        <div class="col-sm-4"><strong>{{ __('Completed Items') }}:</strong></div>
                                        <div class="col-sm-8">{{ $progressData['completed_items'] ?? 0 }}</div>
                                    </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Progress Overview -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-bar"></i> {{ __('Progress Overview') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h5>{{ __('Overall Progress') }}</h5>
                                <div class="progress mb-3" style="height: 30px;">
                                    <div class="progress-bar 
                                        @if($tracking->status === 'not_started') bg-warning
                                        @elseif($tracking->status === 'in_progress') bg-info
                                        @else bg-success
                                        @endif" 
                                        role="progressbar" 
                                        style="width: {{ $progressData['progress_percentage'] }}%" 
                                        aria-valuenow="{{ $progressData['progress_percentage'] }}" 
                                        aria-valuemin="0" 
                                        aria-valuemax="100">
                                        {{ number_format($progressData['progress_percentage'], 1) }}%
                                    </div>
                                </div>
                                <p class="text-muted">
                                    {{ $progressData['completed_items'] ?? 0 }} {{ __('of') }} {{ $progressData['total_items'] ?? 0 }} {{ __('curriculum items completed') }}
                                </p>
                                <p class="text-muted small">
                                    {{ $progressData['completed_chapters'] }} {{ __('of') }} {{ $progressData['total_chapters'] }} {{ __('chapters completed') }}
                                </p>
                            </div>
                            <div class="col-md-6">
                                <h5>{{ __('Current Status') }}</h5>
                                <div class="text-left">
                                    <span class="badge badge-lg 
                                        @if($tracking->status === 'not_started') badge-warning
                                        @elseif($tracking->status === 'in_progress') badge-info
                                        @else badge-success
                                        @endif" style="font-size: 1.2em; padding: 10px 20px;">
                                        @if($tracking->status === 'not_started')
                                            {{ __('Not Started') }}
                                        @elseif($tracking->status === 'in_progress')
                                            {{ __('In Progress') }}
                                        @else
                                            {{ __('Completed') }}
                                        @endif
                                    </span>
                                </div>
                                
                                <!-- Progress Update Form -->
                                        <p class="text-muted small mt-2">
                                            {{ __('Progress is calculated automatically based on curriculum items completion.') }}
                                        </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chapter Progress Details -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-list"></i> {{ __('Chapter Progress Details') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>{{ __('Chapter') }}</th>
                                        <th>{{ __('Items') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Completed At') }}</th>
                                        <th>{{ __('Progress') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($progressData['chapters'] as $chapter)
                                        <tr>
                                            <td>
                                                <strong>{{ $chapter['title'] }}</strong>
                                            </td>
                                            <td>
                                                <div>
                                                    <span class="badge badge-info">{{ $chapter['lectures_count'] ?? 0 }} {{ __('Lectures') }}</span>
                                                    <span class="badge badge-warning">{{ $chapter['quizzes_count'] ?? 0 }} {{ __('Quizzes') }}</span>
                                                    <span class="badge badge-primary">{{ $chapter['assignments_count'] ?? 0 }} {{ __('Assignments') }}</span>
                                                    <span class="badge badge-secondary">{{ $chapter['resources_count'] ?? 0 }} {{ __('Resources') }}</span>
                                                </div>
                                                <small class="text-muted">{{ $chapter['completed_items'] ?? 0 }}/{{ $chapter['total_items'] ?? 0 }} {{ __('completed') }}</small>
                                            </td>
                                            <td>
                                                <span class="badge 
                                                    @if($chapter['status'] === 'not_started') badge-secondary
                                                    @elseif($chapter['status'] === 'in_progress') badge-warning
                                                    @else badge-success
                                                    @endif">
                                                    @if($chapter['status'] === 'not_started')
                                                        {{ __('Not Started') }}
                                                    @elseif($chapter['status'] === 'in_progress')
                                                        {{ __('In Progress') }}
                                                    @else
                                                        {{ __('Completed') }}
                                                    @endif
                                                </span>
                                            </td>
                                            <td>
                                                @if($chapter['completed_at'])
                                                    {{ \Carbon\Carbon::parse($chapter['completed_at'])->format('d M Y, H:i') }}
                                                @else
                                                    <span class="text-muted">{{ __('Not completed') }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                @php
                                                    $chapterProgress = $chapter['progress_percentage'] ?? 0;
                                                @endphp
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar 
                                                        @if($chapter['status'] === 'not_started') bg-secondary
                                                        @elseif($chapter['status'] === 'in_progress') bg-warning
                                                        @else bg-success
                                                        @endif" 
                                                        role="progressbar" 
                                                        style="width: {{ $chapterProgress }}%" 
                                                        aria-valuenow="{{ $chapterProgress }}" 
                                                        aria-valuemin="0" 
                                                        aria-valuemax="100">
                                                        {{ number_format($chapterProgress, 1) }}%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">
                                                {{ __('No chapters found for this course.') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Progress Statistics -->
        <div class="row">
            <div class="col-lg-2 col-md-4 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-primary">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Chapters') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ $progressData['total_chapters'] }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Completed Chapters') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ $progressData['completed_chapters'] }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-info">
                        <i class="fas fa-play"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Lectures') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ $progressData['total_lectures'] }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-primary">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Items') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ $progressData['total_items'] ?? 0 }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-success">
                        <i class="fas fa-check-double"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Completed Items') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ $progressData['completed_items'] ?? 0 }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-warning">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Progress') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ number_format($progressData['progress_percentage'], 1) }}%
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('library/datatables/media/js/jquery.dataTables.min.js') }}"></script>
    <script>
        $(document).ready(function() {
            $('.table').DataTable({
                "paging": false,
                "searching": false,
                "ordering": true,
                "info": false
            });
        });
    </script>
@endpush

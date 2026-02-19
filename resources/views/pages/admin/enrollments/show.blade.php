@extends('layouts.app')

@section('title')
    {{ __('Enrollment Details') }}
@endsection

@section('main')
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-graduation-cap"></i> {{ __('Enrollment Details') }}</h1>
            <div class="section-header-button">
                <a href="{{ route('admin.enrollments.index') }}" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> {{ __('Back to Enrollments') }}
                </a>
            </div>
        </div>

        <div class="row">
            <!-- Student Information -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-user"></i> {{ __('Student Information') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="d-flex justify-content-center mb-3">
                                <div class="avatar avatar-xl bg-primary text-white d-flex align-items-center justify-content-center" style="width: 80px; height: 80px; border-radius: 50%; font-size: 2rem; font-weight: bold;">
                                    {{ substr($enrollment->order->user->name ?? 'N/A', 0, 1) }}
                                </div>
                            </div>
                            <h5 class="mt-2 mb-1">{{ $enrollment->order->user->name ?? 'N/A' }}</h5>
                            <p class="text-muted mb-0">{{ $enrollment->order->user->email ?? 'N/A' }}</p>
                        </div>
                        
                        <table class="table table-borderless mb-0">
                            <tr>
                                <td class="w-50"><strong>{{ __('Phone') }}:</strong></td>
                                <td>{{ $enrollment->order->user->phone ?? 'N/A' }}</td>
                            </tr>
                            <tr>
                                <td><strong>{{ __('Registration Date') }}:</strong></td>
                                <td>{{ $enrollment->order->user->created_at ? $enrollment->order->user->created_at->format('d M Y') : 'N/A' }}</td>
                            </tr>
                            <tr>
                                <td><strong>{{ __('Total Enrollments') }}:</strong></td>
                                <td><span class="badge badge-primary">{{ $enrollment->order->user->orders->sum(function($order) { return $order->orderCourses->count(); }) ?? 0 }}</span></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Course Information -->
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-book"></i> {{ __('Course Information') }}</h4>
                    </div>
                    <div class="card-body">
                        <h5 class="mb-3">{{ $enrollment->course->title ?? 'N/A' }}</h5>
                        @if($enrollment->course->description)
                            <p class="text-muted mb-3">{{ Str::limit($enrollment->course->description, 150) }}</p>
                        @endif
                        
                        <table class="table table-borderless mb-0">
                            <tr>
                                <td class="w-50"><strong>{{ __('Instructor') }}:</strong></td>
                                <td>{{ $enrollment->course->user->name ?? 'N/A' }}</td>
                            </tr>
                            <tr>
                                <td><strong>{{ __('Price') }}:</strong></td>
                                <td><strong class="text-success">₹{{ number_format($enrollment->price) }}</strong></td>
                            </tr>
                            <tr>
                                <td><strong>{{ __('Duration') }}:</strong></td>
                                <td>{{ $enrollment->course->duration ?? 'N/A' }}</td>
                            </tr>
                            <tr>
                                <td><strong>{{ __('Level') }}:</strong></td>
                                <td>{{ ucfirst($enrollment->course->level ?? 'N/A') }}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Enrollment Details -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-info-circle"></i> {{ __('Enrollment Details') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <td class="w-50"><strong>{{ __('Enrollment ID') }}:</strong></td>
                                        <td><span class="badge badge-secondary">#{{ $enrollment->id }}</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Enrollment Date') }}:</strong></td>
                                        <td>{{ $enrollment->created_at->format('d M Y, H:i') }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Order Number') }}:</strong></td>
                                        <td><span class="badge badge-secondary">#{{ $enrollment->order->order_number ?? $enrollment->order->id }}</span></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <td class="w-50"><strong>{{ __('Payment Method') }}:</strong></td>
                                        <td><span class="badge badge-info">{{ ucfirst($enrollment->order->payment_method) }}</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Order Status') }}:</strong></td>
                                        <td>
                                            @switch($enrollment->order->status)
                                                @case('pending')
                                                    <span class="badge badge-warning">{{ __('Pending') }}</span>
                                                    @break
                                                @case('completed')
                                                    <span class="badge badge-success">{{ __('Completed') }}</span>
                                                    @break
                                                @case('cancelled')
                                                    <span class="badge badge-danger">{{ __('Cancelled') }}</span>
                                                    @break
                                                @default
                                                    <span class="badge badge-secondary">{{ ucfirst($enrollment->order->status) }}</span>
                                            @endswitch
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Course Content -->
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-list"></i> {{ __('Course Content') }}</h4>
                    </div>
                    <div class="card-body">
                        @if($enrollment->course->chapters->count() > 0)
                            <div class="accordion" id="courseChapters">
                                @foreach($enrollment->course->chapters as $index => $chapter)
                                    <div class="card">
                                        <div class="card-header" id="heading{{ $chapter->id }}">
                                            <h5 class="mb-0">
                                                <button class="btn btn-link text-left w-100 d-flex justify-content-between align-items-center" type="button" data-toggle="collapse" data-target="#collapse{{ $chapter->id }}" aria-expanded="{{ $index == 0 ? 'true' : 'false' }}" aria-controls="collapse{{ $chapter->id }}">
                                                    <span>
                                                        <i class="fas fa-chevron-down mr-2"></i> {{ $chapter->title }}
                                                    </span>
                                                    <span class="badge badge-secondary ml-2">{{ $chapter->lectures->count() }} {{ __('lectures') }}</span>
                                                </button>
                                            </h5>
                                        </div>
                                        <div id="collapse{{ $chapter->id }}" class="collapse {{ $index == 0 ? 'show' : '' }}" aria-labelledby="heading{{ $chapter->id }}" data-parent="#courseChapters">
                                            <div class="card-body">
                                                @if($chapter->lectures->count() > 0)
                                                    <div class="list-group">
                                                        @foreach($chapter->lectures as $lecture)
                                                            <div class="list-group-item d-flex justify-content-between align-items-start">
                                                                <div class="flex-grow-1">
                                                                    <h6 class="mb-1">{{ $lecture->title }}</h6>
                                                                    @if($lecture->description)
                                                                        <small class="text-muted d-block">{{ Str::limit($lecture->description, 100) }}</small>
                                                                    @endif
                                                                </div>
                                                                <div class="d-flex align-items-center gap-2 ml-3">
                                                                    <span class="badge badge-info">{{ ucfirst($lecture->type) }}</span>
                                                                    @if($lecture->duration)
                                                                        <span class="badge badge-secondary">{{ $lecture->duration }}</span>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <p class="text-muted mb-0">{{ __('No lectures available in this chapter.') }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center text-muted">
                                <i class="fas fa-book fa-3x mb-3"></i>
                                <p>{{ __('No course content available.') }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Enrollment Actions -->
                <div class="card">
                        <div class="card-header">
                            <h4><i class="fas fa-cogs"></i> {{ __('Actions') }}</h4>
                        </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <a href="{{ route('admin.orders.show', $enrollment->order->id) }}" class="btn btn-primary btn-block">
                                    <i class="fas fa-shopping-cart"></i> {{ __('View Order Details') }}
                                </a>
                            </div>
                            <div class="col-md-3">
                                @php
                                    // Safely get user ID from enrollment
                                    $userId = null;
                                    $courseId = null;
                                    
                                    if ($enrollment->order && $enrollment->order->user) {
                                        $userId = $enrollment->order->user->id;
                                    }
                                    
                                    if ($enrollment->course) {
                                        $courseId = $enrollment->course->id;
                                    }
                                    
                                    // Ensure both IDs are valid integers greater than 0
                                    $userId = ($userId && is_numeric($userId) && (int)$userId > 0) ? (int)$userId : null;
                                    $courseId = ($courseId && is_numeric($courseId) && (int)$courseId > 0) ? (int)$courseId : null;
                                    $trackingId = ($userId && $courseId) ? $userId . '_' . $courseId : null;
                                @endphp
                                @if($trackingId)
                                    <a href="{{ route('admin.tracking.show', $trackingId) }}" class="btn btn-info btn-block">
                                        <i class="fas fa-chart-line"></i> {{ __('View Progress') }}
                                    </a>
                                @else
                                    <button class="btn btn-info btn-block" disabled title="{{ __('Unable to load progress: Missing user or course information') }}">
                                        <i class="fas fa-chart-line"></i> {{ __('View Progress') }}
                                    </button>
                                    @if(config('app.debug'))
                                        <small class="text-muted d-block mt-1">
                                            Debug: User ID={{ $userId ?? 'null' }}, Course ID={{ $courseId ?? 'null' }}
                                        </small>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('style')
<style>
    .table-borderless td {
        padding: 0.5rem 0;
        vertical-align: middle;
    }
    .table-borderless tr:last-child td {
        padding-bottom: 0;
    }
    .card-body .table-borderless {
        margin-bottom: 0;
    }
    .list-group-item {
        border-left: none;
        border-right: none;
        padding: 1rem;
    }
    .list-group-item:first-child {
        border-top: none;
    }
    .list-group-item:last-child {
        border-bottom: none;
    }
    .accordion .card {
        border: 1px solid #e3e6f0;
        margin-bottom: 0.5rem;
    }
    .accordion .card-header {
        background-color: #f8f9fc;
        border-bottom: 1px solid #e3e6f0;
        padding: 0.75rem 1rem;
    }
    .accordion .btn-link {
        color: #5a5c69;
        text-decoration: none;
        font-weight: 500;
    }
    .accordion .btn-link:hover {
        color: #3a3c49;
        text-decoration: none;
    }
    .accordion .btn-link:focus {
        box-shadow: none;
    }
    .accordion .collapse.show .card-body {
        padding: 1rem;
    }
    .gap-2 {
        gap: 0.5rem;
    }
    .avatar {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin: 0 auto !important;
        text-align: center;
    }
    .card-body .text-center .d-flex {
        justify-content: center;
    }
</style>
@endpush

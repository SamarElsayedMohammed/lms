@extends('layouts.app')

@section('title', 'Rating Details')

@section('main')
<section class="section">
    <div class="section-header">
        <h1>{{ __('Rating Details') }}</h1>
        <div class="section-header-breadcrumb">
            <div class="breadcrumb-item active"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></div>
            <div class="breadcrumb-item"><a href="{{ route('admin.ratings.index') }}">{{ __('Ratings') }}</a></div>
            <div class="breadcrumb-item">{{ __('Rating #') }}{{ $rating->id }}</div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4>{{ __('Rating Information') }}</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>{{ __('Rating ID') }}:</strong></td>
                                    <td>{{ $rating->id }}</td>
                                </tr>
                                <tr>
                                    <td><strong>{{ __('User') }}:</strong></td>
                                    <td>
                                        <div>
                                            <strong>{{ $rating->user->name ?? 'N/A' }}</strong><br>
                                            <small class="text-muted">{{ $rating->user->email ?? 'N/A' }}</small>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>{{ __('Type') }}:</strong></td>
                                    <td>
                                        @if($rating->rateable_type == 'App\\Models\\Course\\Course')
                                            <span class="badge badge-info">{{ __('Course') }}</span>
                                        @elseif($rating->rateable_type == 'App\\Models\\Instructor')
                                            <span class="badge badge-warning">{{ __('Instructor') }}</span>
                                        @else
                                            <span class="badge badge-secondary">{{ __('Unknown') }}</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>{{ __('Item') }}:</strong></td>
                                    <td>
                                        <div>
                                            @if($rating->rateable)
                                                <strong>{{ $rating->rateable->title ?? 'N/A' }}</strong><br>
                                                <small class="text-muted">ID: {{ $rating->rateable_id }}</small>
                                            @else
                                                <strong class="text-muted">{{ __('Item Deleted') }}</strong><br>
                                                <small class="text-muted">ID: {{ $rating->rateable_id }}</small>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>{{ __('Rating') }}:</strong></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="mr-2 h4 mb-0">{{ $rating->rating }}</span>
                                            <div class="stars">
                                                @for($i = 1; $i <= 5; $i++)
                                                    @if($i <= $rating->rating)
                                                        <i class="fas fa-star text-warning fa-lg"></i>
                                                    @else
                                                        <i class="far fa-star text-muted fa-lg"></i>
                                                    @endif
                                                @endfor
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>{{ __('Created At') }}:</strong></td>
                                    <td>
                                        {{ $rating->created_at->format('F d, Y') }}<br>
                                        <small class="text-muted">{{ $rating->created_at->format('h:i A') }}</small>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>{{ __('Updated At') }}:</strong></td>
                                    <td>
                                        {{ $rating->updated_at->format('F d, Y') }}<br>
                                        <small class="text-muted">{{ $rating->updated_at->format('h:i A') }}</small>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            @if($rating->review)
                            <div class="review-section">
                                <h5>{{ __('Review') }}</h5>
                               
                                    <div class="card-body">
                                        <p class="card-text">{{ $rating->review }}</p>
                                    </div>
                                
                            </div>
                            @else
                            <div class="review-section">
                                <h5>{{ __('Review') }}</h5>
                               
                                    <div class="card-body text-center">
                                        <i class="fas fa-comment-slash fa-2x text-muted mb-2"></i>
                                        <p class="text-muted">{{ __('No review provided') }}</p>
                                    </div>
                                
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- User Information Card -->
            <div class="card">
                <div class="card-header">
                    <h4>{{ __('User Information') }}</h4>
                </div>
                <div class="card-body">
                    @if($rating->user)
                    <div class="text-center mb-3">
                        @php
                            $profileUrl = asset('img/avatar/avatar-1.png');
                            if ($rating->user->profile) {
                                if (str_starts_with($rating->user->profile, 'http://') || str_starts_with($rating->user->profile, 'https://')) {
                                    $profileUrl = $rating->user->profile;
                                } else {
                                    $profileUrl = asset('storage/' . $rating->user->profile);
                                }
                            }
                        @endphp
                        <img src="{{ $profileUrl }}" alt="User Avatar" class="rounded-circle" width="80" height="80">
                        <h5 class="mt-2">{{ $rating->user->name }}</h5>
                        <p class="text-muted">{{ $rating->user->email }}</p>
                    </div>
                    
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td><strong>{{ __('User ID') }}:</strong></td>
                            <td>{{ $rating->user->id ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('Member Since') }}:</strong></td>
                            <td>{{ $rating->user->created_at ? $rating->user->created_at->format('M d, Y') : 'N/A' }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('Total Ratings') }}:</strong></td>
                            <td>{{ $rating->user ? $rating->user->ratings()->count() : 0 }}</td>
                        </tr>
                    </table>
                    @else
                    <div class="text-center">
                        <i class="fas fa-user-slash fa-2x text-muted mb-2"></i>
                        <p class="text-muted">{{ __('User not found') }}</p>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Item Information Card -->
            <div class="card">
                <div class="card-header">
                    <h4>
                        @if($rating->rateable_type == 'App\\Models\\Course\\Course')
                            {{ __('Course Information') }}
                        @elseif($rating->rateable_type == 'App\\Models\\Instructor')
                            {{ __('Instructor Information') }}
                        @else
                            {{ __('Item Information') }}
                        @endif
                    </h4>
                </div>
                <div class="card-body">
                    @if($rating->rateable)
                    <div class="text-center mb-3">
                        @if($rating->rateable_type == 'App\\Models\\Course\\Course')
                            <i class="fas fa-book fa-3x text-primary mb-2"></i>
                        @elseif($rating->rateable_type == 'App\\Models\\Instructor')
                            <i class="fas fa-chalkboard-teacher fa-3x text-warning mb-2"></i>
                        @else
                            <i class="fas fa-question-circle fa-3x text-secondary mb-2"></i>
                        @endif
                        <h5>{{ $rating->rateable->title ?? 'N/A' }}</h5>
                    </div>
                    
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td><strong>{{ __('ID') }}:</strong></td>
                            <td>{{ $rating->rateable_id }}</td>
                        </tr>
                        @if($rating->rateable_type == 'App\\Models\\Course\\Course')
                        <tr>
                            <td><strong>{{ __('Price') }}:</strong></td>
                            <td>${{ number_format($rating->rateable->price ?? 0, 2) }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('Level') }}:</strong></td>
                            <td>{{ $rating->rateable->level ?? 'N/A' }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td><strong>{{ __('Created') }}:</strong></td>
                            <td>{{ $rating->rateable && $rating->rateable->created_at ? $rating->rateable->created_at->format('M d, Y') : 'N/A' }}</td>
                        </tr>
                    </table>
                    @else
                    <div class="text-center">
                        <i class="fas fa-exclamation-triangle fa-2x text-muted mb-2"></i>
                        <p class="text-muted">{{ __('Item Deleted') }}</p>
                        <small class="text-muted">{{ __('The rated item has been deleted') }}</small>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Actions Card -->
            <div class="card">
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="{{ route('admin.ratings.index') }}" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> {{ __('Back to Ratings') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

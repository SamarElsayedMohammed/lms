@extends('layouts.app')

@section('title', 'Ratings Management')

@section('page-title')
    <h1 class="mb-0">{{ __('Ratings Management') }}</h1>
@endsection

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">{{ __('All Ratings & Reviews') }}</h4>

                    <!-- Rating Breakdowns Row -->
                    <div class="row mb-4">
                        <div class="col-lg-4 col-md-4 mb-3">
                            <x-rating-breakdown
                                :label="__('All Ratings')"
                                :average="$stats['total']['average']"
                                :count="$stats['total']['count']"
                                :breakdown="$stats['total']['breakdown']"
                            />
                        </div>
                        <div class="col-lg-4 col-md-4 mb-3">
                            <x-rating-breakdown
                                :label="__('Course Ratings')"
                                :average="$stats['courses']['average']"
                                :count="$stats['courses']['count']"
                                :breakdown="$stats['courses']['breakdown']"
                            />
                        </div>
                        <div class="col-lg-4 col-md-4 mb-3">
                            <x-rating-breakdown
                                :label="__('Instructor Ratings')"
                                :average="$stats['instructors']['average']"
                                :count="$stats['instructors']['count']"
                                :breakdown="$stats['instructors']['breakdown']"
                            />
                        </div>
                    </div>

                    <!-- Filters -->
                    <form method="GET" action="{{ route('admin.ratings.index') }}" class="mb-4">
                        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3">
                            <!-- Left Side: Filter Groups -->
                            <div class="d-flex flex-wrap align-items-end gap-3">
                                <!-- Type & Rating Group -->
                                <div class="d-flex gap-2 align-items-end">
                                    <div>
                                        <label class="form-label text-muted small mb-1">{{ __('Type') }}</label>
                                        <select name="type" class="form-select" style="height: 38px; min-width: 130px;">
                                            <option value="">{{ __('All Types') }}</option>
                                            <option value="course" {{ request('type') == 'course' ? 'selected' : '' }}>{{ __('Courses') }}</option>
                                            <option value="instructor" {{ request('type') == 'instructor' ? 'selected' : '' }}>{{ __('Instructors') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label text-muted small mb-1">{{ __('Rating') }}</label>
                                        <select name="rating" class="form-select" style="height: 38px; min-width: 130px;">
                                            <option value="">{{ __('All Ratings') }}</option>
                                            @for($i = 5; $i >= 1; $i--)
                                                <option value="{{ $i }}" {{ request('rating') == $i ? 'selected' : '' }}>{{ $i }} {{ __('Star') }}{{ $i > 1 ? 's' : '' }}</option>
                                            @endfor
                                        </select>
                                    </div>
                                </div>

                                <!-- Date Range Group -->
                                <div class="d-flex gap-2 align-items-end">
                                    <div>
                                        <label class="form-label text-muted small mb-1">{{ __('Date From') }}</label>
                                        <input type="date" name="date_from" class="form-control" style="height: 38px; min-width: 150px;" value="{{ request('date_from') }}">
                                    </div>
                                    <div>
                                        <label class="form-label text-muted small mb-1">{{ __('Date To') }}</label>
                                        <input type="date" name="date_to" class="form-control" style="height: 38px; min-width: 150px;" value="{{ request('date_to') }}">
                                    </div>
                                </div>
                            </div>

                            <!-- Right Side: Search & Actions -->
                            <div class="d-flex gap-2 align-items-end">
                                <div>
                                    <label class="form-label text-muted small mb-1">{{ __('Search') }}</label>
                                    <input type="text" name="search" class="form-control" style="height: 38px; width: 220px;" placeholder="{{ __('Search...') }}" value="{{ request('search') }}">
                                </div>
                                <button type="submit" class="btn btn-primary d-flex align-items-center justify-content-center" style="height: 38px; width: 38px;" title="{{ __('Filter') }}">
                                    <i class="fas fa-search"></i>
                                </button>
                                <a href="{{ route('admin.ratings.index') }}" class="btn btn-outline-secondary d-flex align-items-center justify-content-center" style="height: 38px; width: 38px;" title="{{ __('Clear') }}">
                                    <i class="fas fa-times"></i>
                                </a>
                            </div>
                        </div>
                    </form>

                    <!-- Ratings Table -->
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>{{ __('ID') }}</th>
                                    <th>{{ __('User') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Item') }}</th>
                                    <th>{{ __('Rating') }}</th>
                                    <th>{{ __('Review') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($ratings as $rating)
                                <tr>
                                    <td>{{ $rating->id }}</td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar avatar-sm mr-3">
                                                @php
                                                    $profileUrl = asset('img/avatar/avatar-1.png');
                                                    if ($rating->user && $rating->user->profile) {
                                                        if (str_starts_with($rating->user->profile, 'http://') || str_starts_with($rating->user->profile, 'https://')) {
                                                            $profileUrl = $rating->user->profile;
                                                        } else {
                                                            $profileUrl = asset('storage/' . $rating->user->profile);
                                                        }
                                                    }
                                                @endphp
                                                <img src="{{ $profileUrl }}" alt="avatar" class="rounded-circle">
                                            </div>
                                            <div>
                                                <strong>{{ $rating->user->name ?? 'N/A' }}</strong><br>
                                                <small class="text-muted">{{ $rating->user->email ?? 'N/A' }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        @if($rating->rateable_type == 'App\\Models\\Course\\Course')
                                            <span class="badge badge-info">{{ __('Course') }}</span>
                                        @elseif($rating->rateable_type == 'App\\Models\\Instructor')
                                            <span class="badge badge-warning">{{ __('Instructor') }}</span>
                                        @else
                                            <span class="badge badge-secondary">{{ __('Unknown') }}</span>
                                        @endif
                                    </td>
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
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="mr-1">{{ $rating->rating }}</span>
                                            <div class="stars">
                                                @for($i = 1; $i <= 5; $i++)
                                                    @if($i <= $rating->rating)
                                                        <i class="fas fa-star text-warning"></i>
                                                    @else
                                                        <i class="far fa-star text-muted"></i>
                                                    @endif
                                                @endfor
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        @if($rating->review)
                                            <div class="review-text" style="max-width: 200px;">
                                                {{ \Illuminate\Support\Str::limit($rating->review, 100) }}
                                                @if(strlen($rating->review) > 100)
                                                    <a href="#" onclick="showFullReview({{ $rating->id }})" class="text-primary">{{ __('Read more') }}</a>
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-muted">{{ __('No review') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $rating->created_at->format('M d, Y') }}<br>
                                        <small class="text-muted">{{ $rating->created_at->format('h:i A') }}</small>
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.ratings.show', $rating->id) }}" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> {{ __('View') }}
                                        </a>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <div class="empty-state">
                                            <i class="fas fa-star fa-3x text-muted"></i>
                                            <h5 class="mt-2">{{ __('No ratings found') }}</h5>
                                            <p class="text-muted">{{ __('There are no ratings matching your criteria.') }}</p>
                                        </div>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    @if($ratings->hasPages())
                    <div class="d-flex justify-content-center mt-4">
                        <nav aria-label="Page navigation">
                            <ul class="pagination pagination-md mb-0">
                                {{-- Previous Page Link --}}
                                @if ($ratings->onFirstPage())
                                    <li class="page-item disabled">
                                        <span class="page-link" aria-label="Previous">
                                            <span aria-hidden="true">&laquo; Previous</span>
                                        </span>
                                    </li>
                                @else
                                    <li class="page-item">
                                        <a class="page-link" href="{{ $ratings->appends(request()->query())->previousPageUrl() }}" aria-label="Previous">
                                            <span aria-hidden="true">&laquo; Previous</span>
                                        </a>
                                    </li>
                                @endif

                                {{-- Pagination Elements --}}
                                @php
                                    $currentPage = $ratings->currentPage();
                                    $lastPage = $ratings->lastPage();
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
                                        <a class="page-link" href="{{ $ratings->appends(request()->query())->url(1) }}">1</a>
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
                                            <a class="page-link" href="{{ $ratings->appends(request()->query())->url($page) }}">{{ $page }}</a>
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
                                        <a class="page-link" href="{{ $ratings->appends(request()->query())->url($lastPage) }}">{{ $lastPage }}</a>
                                    </li>
                                @endif

                                {{-- Next Page Link --}}
                                @if ($ratings->hasMorePages())
                                    <li class="page-item">
                                        <a class="page-link" href="{{ $ratings->appends(request()->query())->nextPageUrl() }}" aria-label="Next">
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
                            Showing {{ $ratings->firstItem() }} to {{ $ratings->lastItem() }} of {{ $ratings->total() }} results
                        </p>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Full Review') }}</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="fullReviewContent"></div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('script')
<script>
function refreshRatings() {
    location.reload();
}

function showFullReview(ratingId) {
    // This would typically fetch the full review via AJAX
    // For now, we'll show a placeholder
    document.getElementById('fullReviewContent').innerHTML = '<p>Loading full review...</p>';
    $('#reviewModal').modal('show');
}

</script>
@endpush

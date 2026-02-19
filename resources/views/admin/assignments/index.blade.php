@extends('layouts.app')
@section('title')
    {{ __('Assignment Submissions') }}
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
                    <form method="GET" action="{{ route('admin.assignments.index') }}">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-3 col-sm-6">
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
                            <div class="col-md-3 col-sm-6">
                                <label class="form-label text-muted small mb-1">Instructor</label>
                                <select name="instructor_id" class="form-control">
                                    <option value="">All Instructors</option>
                                    @foreach($instructors as $instructor)
                                        <option value="{{ $instructor->id }}" {{ request('instructor_id') == $instructor->id ? 'selected' : '' }}>
                                            {{ $instructor->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <label class="form-label text-muted small mb-1">Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="submitted" {{ request('status') == 'submitted' ? 'selected' : '' }}>Pending</option>
                                    <option value="accepted" {{ request('status') == 'accepted' ? 'selected' : '' }}>Accepted</option>
                                    <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>Rejected</option>
                                </select>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <label class="form-label text-muted small mb-1">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Search by student, course, or assignment" value="{{ request('search') }}">
                            </div>
                            <div class="col-md-1 col-sm-12 d-flex justify-content-end">
                                <div class="d-flex w-100 gap-2 flex-wrap">
                                    <button type="submit" class="btn btn-primary flex-fill">
                                        <i class="fas fa-search mr-2"></i>Filter
                                    </button>
                                    <a href="{{ route('admin.assignments.index') }}" class="btn btn-secondary flex-fill">
                                        <i class="fas fa-sync-alt mr-2"></i>Clear
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Submissions List -->
            <div class="card">
                <div class="card-header">
                    <h4>Assignment Submissions ({{ $submissions->total() }})</h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" onclick="bulkAccept()">Bulk Accept</button>
                        <button class="btn btn-danger" onclick="bulkReject()">Bulk Reject</button>
                        <button class="btn btn-warning" onclick="bulkSuspend()">Bulk Suspend</button>
                    </div>
                </div>
                <div class="card-body">

                    @if($submissions->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>
                                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                        </th>
                                        <th>Student</th>
                                        <th>Assignment</th>
                                        <th>Course</th>
                                        <th>Instructor</th>
                                        <th>Status</th>
                                        <th>Points</th>
                                        <th>Submitted</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($submissions as $submission)
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="submission-checkbox" value="{{ $submission->id }}">
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar avatar-sm mr-3">
                                                        <img src="{{ $submission->user->profile ? asset('storage/' . $submission->user->profile) : asset('img/avatar/avatar-1.png') }}" alt="avatar">
                                                    </div>
                                                    <div>
                                                        <div class="font-weight-600">{{ $submission->user->name }}</div>
                                                        <div class="text-small text-muted">{{ $submission->user->email }}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="font-weight-600">{{ $submission->assignment->title ?? 'N/A' }}</div>
                                                <div class="text-small text-muted">Max Points: {{ $submission->assignment->points ?? 'N/A' }}</div>
                                            </td>
                                            <td>
                                                <div class="font-weight-600">
                                                    @if($submission->assignment && $submission->assignment->chapter && $submission->assignment->chapter->course)
                                                        {{ $submission->assignment->chapter->course->title }}
                                                    @else
                                                        <span class="text-muted">Course not found</span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td>
                                                @if($submission->assignment && $submission->assignment->chapter && $submission->assignment->chapter->course && $submission->assignment->chapter->course->user)
                                                    <div class="font-weight-600">{{ $submission->assignment->chapter->course->user->name }}</div>
                                                    <div class="text-small text-muted">{{ $submission->assignment->chapter->course->user->email }}</div>
                                                @else
                                                    <span class="text-muted">Instructor not found</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($submission->status == 'submitted')
                                                    <span class="badge badge-warning">Pending</span>
                                                @elseif($submission->status == 'accepted')
                                                    <span class="badge badge-success">Accepted</span>
                                                @elseif($submission->status == 'rejected')
                                                    <span class="badge badge-danger">Rejected</span>
                                                @elseif($submission->status == 'suspended')
                                                    <span class="badge badge-warning">Suspended</span>
                                                @else
                                                    <span class="badge badge-secondary">{{ ucfirst($submission->status ?? 'Unknown') }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($submission->points)
                                                    {{ $submission->points }}/{{ $submission->assignment->points }}
                                                @else
                                                    -
                                                @endif
                                            </td>
                                            <td>{{ $submission->created_at->format('M d, Y H:i') }}</td>
                                            <td>
                                                <a href="{{ route('admin.assignments.show', $submission->id) }}" class="btn btn-sm btn-info" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        @if($submissions->hasPages())
                        <div class="d-flex justify-content-center mt-4">
                            <nav aria-label="Page navigation">
                                <ul class="pagination pagination-md mb-0">
                                    {{-- Previous Page Link --}}
                                    @if ($submissions->onFirstPage())
                                        <li class="page-item disabled">
                                            <span class="page-link" aria-label="Previous">
                                                <span aria-hidden="true">&laquo; Previous</span>
                                            </span>
                                        </li>
                                    @else
                                        <li class="page-item">
                                            <a class="page-link" href="{{ $submissions->appends(request()->query())->previousPageUrl() }}" aria-label="Previous">
                                                <span aria-hidden="true">&laquo; Previous</span>
                                            </a>
                                        </li>
                                    @endif

                                    {{-- Pagination Elements --}}
                                    @php
                                        $currentPage = $submissions->currentPage();
                                        $lastPage = $submissions->lastPage();
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
                                            <a class="page-link" href="{{ $submissions->appends(request()->query())->url(1) }}">1</a>
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
                                                <a class="page-link" href="{{ $submissions->appends(request()->query())->url($page) }}">{{ $page }}</a>
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
                                            <a class="page-link" href="{{ $submissions->appends(request()->query())->url($lastPage) }}">{{ $lastPage }}</a>
                                        </li>
                                    @endif

                                    {{-- Next Page Link --}}
                                    @if ($submissions->hasMorePages())
                                        <li class="page-item">
                                            <a class="page-link" href="{{ $submissions->appends(request()->query())->nextPageUrl() }}" aria-label="Next">
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
                                Showing {{ $submissions->firstItem() }} to {{ $submissions->lastItem() }} of {{ $submissions->total() }} results
                            </p>
                        </div>
                        @endif
                    @else
                        <div class="text-center py-4">
                            <h5>No assignment submissions found</h5>
                            <p class="text-muted">
                                @if($submissions->total() == 0)
                                    There are no assignment submissions in the system yet.
                                    <br><br>
                                    <strong>To get started:</strong><br>
                                    1. Create a course with assignments<br>
                                    2. Have students submit assignments<br>
                                    3. Submissions will appear here for review
                                @else
                                    There are no assignment submissions matching your current filters.
                                    <br><br>
                                    <a href="{{ route('admin.assignments.index') }}" class="btn btn-primary">Clear Filters</a>
                                @endif
                            </p>
                        </div>
                    @endif
                </div>
            </div>
    </div>
</section>

<!-- Bulk Action Modal -->
<div class="modal fade" id="bulkActionModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkActionTitle">Bulk Action</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                    <span aria-hidden="true" style="font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff;">&times;</span>
                </button>
            </div>
            <form id="bulkActionForm" method="POST" action="{{ route('admin.assignments.bulk-update') }}">
                @csrf
                @method('PATCH')
                <div class="modal-body">
                    <input type="hidden" name="status" id="bulkStatus">

                    <div class="form-group">
                        <label>Points (for accepted submissions)</label>
                        <input type="number" name="points" class="form-control" min="0" step="0.01">
                    </div>

                    <div class="form-group">
                        <label>Rejection Reason (for rejected/suspended submissions) <span class="text-danger" id="bulk-feedback-required" style="display: none;">*</span></label>
                        <textarea name="feedback" id="bulkFeedback" class="form-control" rows="3"></textarea>
                        <small class="form-text text-muted">Required when status is Reject or Suspend</small>
                    </div>

                    <div class="form-group">
                        <label>Admin Comment</label>
                        <textarea name="admin_comment" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.submission-checkbox');

    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
}

function getSelectedSubmissions() {
    const checkboxes = document.querySelectorAll('.submission-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

function toggleBulkFeedbackRequired(status) {
    const feedbackTextarea = document.getElementById('bulkFeedback');
    const feedbackRequired = document.getElementById('bulk-feedback-required');

    if (status === 'rejected' || status === 'suspended') {
        feedbackTextarea.setAttribute('required', 'required');
        feedbackRequired.style.display = 'inline';
    } else {
        feedbackTextarea.removeAttribute('required');
        feedbackRequired.style.display = 'none';
    }
}

function bulkAccept() {
    const selectedIds = getSelectedSubmissions();
    if (selectedIds.length === 0) {
        showSwalWarningToast('{{ __("Please select at least one submission") }}', '');
        return;
    }

    // Clear existing hidden inputs
    $('#bulkActionForm').find('input[name^="submission_ids"]').remove();
    
    // Add each ID as a separate hidden input (Laravel array format)
    selectedIds.forEach(function(id) {
        $('#bulkActionForm').append('<input type="hidden" name="submission_ids[]" value="' + id + '">');
    });
    
    document.getElementById('bulkStatus').value = 'accepted';
    document.getElementById('bulkActionTitle').textContent = 'Bulk Accept Submissions';
    document.getElementById('bulkActionForm').action = '{{ route("admin.assignments.bulk-update") }}';

    toggleBulkFeedbackRequired('accepted');
    $('#bulkActionModal').modal('show');
}

function bulkReject() {
    const selectedIds = getSelectedSubmissions();
    if (selectedIds.length === 0) {
        showSwalWarningToast('{{ __("Please select at least one submission") }}', '');
        return;
    }

    // Clear existing hidden inputs
    $('#bulkActionForm').find('input[name^="submission_ids"]').remove();
    
    // Add each ID as a separate hidden input (Laravel array format)
    selectedIds.forEach(function(id) {
        $('#bulkActionForm').append('<input type="hidden" name="submission_ids[]" value="' + id + '">');
    });
    
    document.getElementById('bulkStatus').value = 'rejected';
    document.getElementById('bulkActionTitle').textContent = 'Bulk Reject Submissions';
    document.getElementById('bulkActionForm').action = '{{ route("admin.assignments.bulk-update") }}';

    toggleBulkFeedbackRequired('rejected');
    $('#bulkActionModal').modal('show');
}

function bulkSuspend() {
    const selectedIds = getSelectedSubmissions();
    if (selectedIds.length === 0) {
        showSwalWarningToast('{{ __("Please select at least one submission") }}', '');
        return;
    }

    // Clear existing hidden inputs
    $('#bulkActionForm').find('input[name^="submission_ids"]').remove();
    
    // Add each ID as a separate hidden input (Laravel array format)
    selectedIds.forEach(function(id) {
        $('#bulkActionForm').append('<input type="hidden" name="submission_ids[]" value="' + id + '">');
    });
    
    document.getElementById('bulkStatus').value = 'suspended';
    document.getElementById('bulkActionTitle').textContent = 'Bulk Suspend Submissions';
    document.getElementById('bulkActionForm').action = '{{ route("admin.assignments.bulk-update") }}';

    toggleBulkFeedbackRequired('suspended');
    $('#bulkActionModal').modal('show');
}
</script>
@endpush

@extends('layouts.app')

@section('title')
    {{ __('Assignment Submission Details') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('admin.assignments.index') }}">← {{ __('Back to All Submissions') }}</a>
    </div>
@endsection

@section('main')
<div class="content-wrapper">
    <div class="row">
        <div class="col-md-12">
            <div class="row">
                <!-- Submission Details -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h4>Submission Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Student Information</h6>
                                    <p><strong>Name:</strong> {{ $submission->user->name ?? 'N/A' }}</p>
                                    <p><strong>Email:</strong> {{ $submission->user->email ?? 'N/A' }}</p>
                                </div>
                                <div class="col-md-6">
                                    <h6>Assignment Information</h6>
                                    <p><strong>Title:</strong> {{ $submission->assignment->title ?? 'N/A' }}</p>
                                    <p><strong>Max Points:</strong> {{ $submission->assignment->points ?? 'N/A' }}</p>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <h6>Course Information</h6>
                                    <p><strong>Course:</strong> {{ $submission->assignment->chapter->course->title ?? 'N/A' }}</p>
                                    <p><strong>Instructor:</strong> {{ $submission->assignment->chapter->course->user->name ?? 'N/A' }}</p>
                                </div>
                                <div class="col-md-6">
                                    <h6>Submission Details</h6>
                                    <p><strong>Status:</strong> 
                                        @if($submission->status == 'submitted')
                                            <span class="badge badge-warning">Pending</span>
                                        @elseif($submission->status == 'accepted')
                                            <span class="badge badge-success">Accepted</span>
                                        @elseif($submission->status == 'rejected')
                                            <span class="badge badge-danger">Rejected</span>
                                        @elseif($submission->status == 'suspended')
                                            <span class="badge badge-danger">Suspended</span>
                                        @else
                                            <span class="badge badge-secondary">{{ ucfirst($submission->status ?? 'N/A') }}</span>
                                        @endif
                                    </p>
                                    <p><strong>Submitted:</strong> {{ $submission->created_at ? $submission->created_at->format('M d, Y H:i') : 'N/A' }}</p>
                                </div>
                            </div>

                            @if($submission->comment)
                                <div class="mt-3">
                                    <h6>Student Comment</h6>
                                    <div class="">
                                        {{ $submission->comment }}
                                    </div>
                                </div>
                            @endif

                            @if($submission->feedback || $submission->admin_comment)
                                <div class="mt-3">
                                    <h6>Reason / Feedback</h6>
                                    <div class="">
                                        {{ $submission->feedback ?? $submission->admin_comment }}
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Submitted Files -->
                    @if($submission->files->count() > 0)
                        <div class="card">
                            <div class="card-header">
                                <h4>Submitted Files</h4>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    @foreach($submission->files as $file)
                                        <div class="col-md-6 mb-3">
                                            <div class="card">
                                                <div class="card-body">
                                                    @if($file->type == 'file')
                                                        <h6><i class="fas fa-file"></i> {{ basename($file->file) }}</h6>
                                                        <a href="{{ asset('storage/' . $file->file) }}" target="_blank" class="btn btn-sm btn-primary">Download</a>
                                                    @else
                                                        <h6><i class="fas fa-link"></i> URL Submission</h6>
                                                        <a href="{{ $file->url }}" target="_blank" class="btn btn-sm btn-primary">Visit Link</a>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Action Panel -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h4>Actions</h4>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.assignments.update-status', $submission->id) }}">
                                @csrf
                                @method('PATCH')
                                
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status" id="status" class="form-control" required>
                                        <option value="submitted" {{ $submission->status == 'submitted' ? 'selected' : '' }}>Pending</option>
                                        <option value="accepted" {{ $submission->status == 'accepted' ? 'selected' : '' }}>Accept</option>
                                        <option value="rejected" {{ $submission->status == 'rejected' ? 'selected' : '' }}>Reject</option>
                                        <option value="suspended" {{ $submission->status == 'suspended' ? 'selected' : '' }}>Suspend</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Points (for accepted submissions)</label>
                                    <input type="number" name="points" class="form-control" value="{{ $submission->points ?? 0 }}" min="0" max="{{ $submission->assignment->points ?? 100 }}" step="0.01">
                                </div>

                                <div class="form-group">
                                    <label>Reason / Feedback <span class="text-danger" id="feedback-required">*</span></label>
                                    <textarea name="feedback" id="feedback" class="form-control" rows="3">{{ $submission->feedback ?? $submission->admin_comment }}</textarea>
                                    <small class="form-text text-muted">Required when status is Reject or Suspend. Can be used for general feedback.</small>
                                </div>

                                <button type="submit" class="btn btn-primary btn-block">Update Status</button>
                            </form>

                            <div class="mt-3">
                                <a href="{{ route('admin.assignments.index') }}" class="btn btn-secondary btn-block">Back to List</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const statusSelect = document.getElementById('status');
    const feedbackTextarea = document.getElementById('feedback');
    const feedbackRequired = document.getElementById('feedback-required');
    
    function toggleFeedbackRequired() {
        const status = statusSelect.value;
        if (status === 'rejected' || status === 'suspended') {
            feedbackTextarea.setAttribute('required', 'required');
            feedbackRequired.style.display = 'inline';
        } else {
            feedbackTextarea.removeAttribute('required');
            feedbackRequired.style.display = 'none';
        }
    }
    
    // Set initial state
    toggleFeedbackRequired();
    
    // Update on status change
    statusSelect.addEventListener('change', toggleFeedbackRequired);
});
</script>
@endpush

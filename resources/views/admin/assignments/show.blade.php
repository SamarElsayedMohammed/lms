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
                            <h4>{{ __('Submission Information') }}</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>{{ __('Student Information') }}</h6>
                                    <p><strong>{{ __('Name:') }}</strong> {{ $submission->user->name ?? 'N/A' }}</p>
                                    <p><strong>{{ __('Email:') }}</strong> {{ $submission->user->email ?? 'N/A' }}</p>
                                </div>
                                <div class="col-md-6">
                                    <h6>{{ __('Assignment Information') }}</h6>
                                    <p><strong>{{ __('Title') }}:</strong> {{ $submission->assignment->title ?? 'N/A' }}
                                    </p>
                                    <p><strong>{{ __('Max Points:') }}</strong> {{ $submission->assignment->points ??
                                        'N/A' }}</p>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <h6>{{ __('Course Information') }}</h6>
                                    <p><strong>{{ __('Course') }}:</strong> {{
                                        $submission->assignment->chapter->course->title ?? 'N/A' }}</p>
                                    <p><strong>{{ __('Instructor') }}:</strong> {{
                                        $submission->assignment->chapter->course->user->name ?? 'N/A' }}</p>
                                </div>
                                <div class="col-md-6">
                                    <h6>{{ __('Submission Details') }}</h6>
                                    <p><strong>{{ __('Status') }}:</strong>
                                        @if($submission->status == 'submitted')
                                        <span class="badge badge-warning">{{ __('Pending') }}</span>
                                        @elseif($submission->status == 'accepted')
                                        <span class="badge badge-success">{{ __('Accepted') }}</span>
                                        @elseif($submission->status == 'rejected')
                                        <span class="badge badge-danger">{{ __('Rejected') }}</span>
                                        @elseif($submission->status == 'suspended')
                                        <span class="badge badge-danger">{{ __('Suspended') }}</span>
                                        @else
                                        <span class="badge badge-secondary">{{ ucfirst($submission->status ?? 'N/A')
                                            }}</span>
                                        @endif
                                    </p>
                                    <p><strong>{{ __('Submitted') }}:</strong> {{ $submission->created_at ?
                                        $submission->created_at->format('M d, Y H:i') : 'N/A' }}</p>
                                </div>
                            </div>

                            @if($submission->comment)
                            <div class="mt-3">
                                <h6>{{ __('Student Comment') }}</h6>
                                <div class="">
                                    {{ $submission->comment }}
                                </div>
                            </div>
                            @endif

                            @if($submission->feedback || $submission->admin_comment)
                            <div class="mt-3">
                                <h6>{{ __('Reason / Feedback') }}</h6>
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
                            <h4>{{ __('Submitted Files') }}</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                @foreach($submission->files as $file)
                                <div class="col-md-6 mb-3">
                                    <div class="card">
                                        <div class="card-body">
                                            @if($file->type == 'file')
                                            <h6><i class="fas fa-file"></i> {{ basename($file->file) }}</h6>
                                            <a href="{{ asset('storage/' . $file->file) }}" target="_blank"
                                                class="btn btn-sm btn-primary">{{ __('Download') }}</a>
                                            @else
                                            <h6><i class="fas fa-link"></i> {{ __('URL Submission') }}</h6>
                                            <a href="{{ $file->url }}" target="_blank" class="btn btn-sm btn-primary">{{
                                                __('Visit Link') }}</a>
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
                            <h4>{{ __('Actions') }}</h4>
                        </div>
                        <div class="card-body">
                            <form method="POST"
                                action="{{ route('admin.assignments.update-status', $submission->id) }}">
                                @csrf
                                @method('PATCH')

                                <div class="form-group">
                                    <label>{{ __('Status') }}</label>
                                    <select name="status" id="status" class="form-control" required>
                                        <option value="submitted" {{ $submission->status == 'submitted' ? 'selected' :
                                            '' }}>{{ __('Pending') }}</option>
                                        <option value="accepted" {{ $submission->status == 'accepted' ? 'selected' : ''
                                            }}>{{ __('Accept') }}</option>
                                        <option value="rejected" {{ $submission->status == 'rejected' ? 'selected' : ''
                                            }}>{{ __('Reject') }}</option>
                                        <option value="suspended" {{ $submission->status == 'suspended' ? 'selected' :
                                            '' }}>{{ __('Suspend') }}</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>{{ __('Points (for accepted submissions)') }}</label>
                                    <input type="number" name="points" class="form-control"
                                        value="{{ $submission->points ?? 0 }}" min="0"
                                        max="{{ $submission->assignment->points ?? 100 }}" step="0.01">
                                </div>

                                <div class="form-group">
                                    <label>{{ __('Reason / Feedback') }} <span class="text-danger"
                                            id="feedback-required">*</span></label>
                                    <textarea name="feedback" id="feedback" class="form-control"
                                        rows="3">{{ $submission->feedback ?? $submission->admin_comment }}</textarea>
                                    <small class="form-text text-muted">{{ __('Required when status is Reject or
                                        Suspend. Can be used for general feedback.') }}</small>
                                </div>

                                <button type="submit" class="btn btn-primary btn-block">{{ __('Update Status')
                                    }}</button>
                            </form>

                            <div class="mt-3">
                                <a href="{{ route('admin.assignments.index') }}" class="btn btn-secondary btn-block">{{
                                    __('Back to List') }}</a>
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
    document.addEventListener('DOMContentLoaded', function () {
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
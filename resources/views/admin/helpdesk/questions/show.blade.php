@extends('layouts.app')

@section('title', 'Question Details')

@section('main')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header space-between section-header">
                    <h3 class="card-title">
                        <i class="fas fa-question-circle"></i> Question Details
                    </h3>
                    <div class="card-tools">
                        <a href="{{ route('admin.helpdesk.questions.index') }}" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h4>{{ $question->title }}</h4>
                            <p class="text-muted">
                                <i class="fas fa-calendar"></i> {{ $question->created_at->format('M d, Y H:i:s') }}
                                @if($question->is_private)
                                    <span class="badge badge-warning ml-2">Private</span>
                                @endif
                            </p>
                            
                            @if($question->description)
                                <div class="mt-3">
                                    <h6>Description:</h6>
                                    <div class="border p-3 bg-light">
                                        {!! nl2br(e($question->description)) !!}
                                    </div>
                                </div>
                            @endif
                        </div>
                        <div class="col-md-4">
                            <h5>Question Information</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">Question ID:</th>
                                    <td>{{ $question->id }}</td>
                                </tr>
                                <tr>
                                    <th>Slug:</th>
                                    <td>{{ $question->slug }}</td>
                                </tr>
                                <tr>
                                    <th>Is Private:</th>
                                    <td>
                                        @if($question->is_private)
                                            <span class="badge badge-warning">Yes</span>
                                        @else
                                            <span class="badge badge-success">No</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Replies Count:</th>
                                    <td>{{ $question->replies->count() }}</td>
                                </tr>
                                <tr>
                                    <th>Created At:</th>
                                    <td>{{ $question->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                                <tr>
                                    <th>Updated At:</th>
                                    <td>{{ $question->updated_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h5>Group Information</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">Group ID:</th>
                                    <td>{{ $question->group->id }}</td>
                                </tr>
                                <tr>
                                    <th>Group Name:</th>
                                    <td>{{ $question->group->name }}</td>
                                </tr>
                                <tr>
                                    <th>Group Slug:</th>
                                    <td>{{ $question->group->slug }}</td>
                                </tr>
                                <tr>
                                    <th>Description:</th>
                                    <td>{{ $question->group->description ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Is Private:</th>
                                    <td>
                                        @if($question->group->is_private)
                                            <span class="badge badge-info">Yes</span>
                                        @else
                                            <span class="badge badge-secondary">No</span>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h5>Author Information</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">User ID:</th>
                                    <td>{{ $question->user->id }}</td>
                                </tr>
                                <tr>
                                    <th>Name:</th>
                                    <td>{{ $question->user->name }}</td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td>{{ $question->user->email }}</td>
                                </tr>
                                <tr>
                                    <th>Profile:</th>
                                    <td>
                                        @if($question->user->profile)
                                            <img src="{{ $question->user->profile }}" alt="Profile" class="img-thumbnail" style="width: 50px; height: 50px;">
                                        @else
                                            <span class="text-muted">No profile image</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Registered At:</th>
                                    <td>{{ $question->user->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    @if($question->replies->count() > 0)
                    <div class="row mt-4">
                        <div class="col-12">
                            <h5>Replies ({{ $question->replies->count() }})</h5>
                            <div class="replies-container">
                                @foreach($question->replies as $reply)
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>{{ $reply->user->name }}</strong>
                                                <small class="text-muted ml-2">{{ $reply->created_at->format('M d, Y H:i:s') }}</small>
                                            </div>
                                            <div class="col-md-6 text-right">
                                                @if($reply->user->profile)
                                                    <img src="{{ $reply->user->profile }}" alt="Profile" class="img-thumbnail" style="width: 30px; height: 30px;">
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        {!! nl2br(e($reply->reply)) !!}
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    @else
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No replies yet for this question.
                            </div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
$(document).ready(function() {
    // Any additional JavaScript can be added here
});
</script>
@endsection

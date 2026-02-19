@extends('layouts.app')

@section('title', 'Reply Details')

@section('main')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-reply"></i> Reply Details
                    </h3>
                    <div class="card-tools">
                        <a href="{{ route('admin.helpdesk.replies.index') }}" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h4>Reply Content</h4>
                            <div class="border p-3 bg-light">
                                {!! nl2br(e($reply->reply)) !!}
                            </div>
                            <p class="text-muted mt-2">
                                <i class="fas fa-calendar"></i> {{ $reply->created_at->format('M d, Y H:i:s') }}
                            </p>
                        </div>
                        <div class="col-md-4">
                            <h5>Reply Information</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">Reply ID:</th>
                                    <td>{{ $reply->id }}</td>
                                </tr>
                                <tr>
                                    <th>Created At:</th>
                                    <td>{{ $reply->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                                <tr>
                                    <th>Updated At:</th>
                                    <td>{{ $reply->updated_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h5>Question Information</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">Question ID:</th>
                                    <td>{{ $reply->question->id }}</td>
                                </tr>
                                <tr>
                                    <th>Question Title:</th>
                                    <td>{{ $reply->question->title }}</td>
                                </tr>
                                <tr>
                                    <th>Question Slug:</th>
                                    <td>{{ $reply->question->slug }}</td>
                                </tr>
                                <tr>
                                    <th>Is Private:</th>
                                    <td>
                                        @if($reply->question->is_private)
                                            <span class="badge badge-warning">Yes</span>
                                        @else
                                            <span class="badge badge-success">No</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Question Created:</th>
                                    <td>{{ $reply->question->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h5>Author Information</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">User ID:</th>
                                    <td>{{ $reply->user->id }}</td>
                                </tr>
                                <tr>
                                    <th>Name:</th>
                                    <td>{{ $reply->user->name }}</td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td>{{ $reply->user->email }}</td>
                                </tr>
                                <tr>
                                    <th>Profile:</th>
                                    <td>
                                        @if($reply->user->profile)
                                            <img src="{{ $reply->user->profile }}" alt="Profile" class="img-thumbnail" style="width: 50px; height: 50px;">
                                        @else
                                            <span class="text-muted">No profile image</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Registered At:</th>
                                    <td>{{ $reply->user->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-12">
                            <h5>Group Information</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="20%">Group ID:</th>
                                    <td>{{ $reply->question->group->id }}</td>
                                </tr>
                                <tr>
                                    <th>Group Name:</th>
                                    <td>{{ $reply->question->group->name }}</td>
                                </tr>
                                <tr>
                                    <th>Group Slug:</th>
                                    <td>{{ $reply->question->group->slug }}</td>
                                </tr>
                                <tr>
                                    <th>Description:</th>
                                    <td>{{ $reply->question->group->description ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Is Private:</th>
                                    <td>
                                        @if($reply->question->group->is_private)
                                            <span class="badge badge-info">Yes</span>
                                        @else
                                            <span class="badge badge-secondary">No</span>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
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

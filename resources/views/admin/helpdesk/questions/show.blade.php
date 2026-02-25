@extends('layouts.app')

@section('title', __('Question Details'))

@section('main')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header space-between section-header">
                    <h3 class="card-title">
                        <i class="fas fa-question-circle"></i> {{ __('Question Details') }}
                    </h3>
                    <div class="card-tools">
                        <a href="{{ route('admin.helpdesk.questions.index') }}" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> {{ __('Back to List') }}
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
                                <span class="badge badge-warning ml-2">{{ __('Private') }}</span>
                                @endif
                            </p>

                            @if($question->description)
                            <div class="mt-3">
                                <h6>{{ __('Description:') }}</h6>
                                <div class="border p-3 bg-light">
                                    {!! nl2br(e($question->description)) !!}
                                </div>
                            </div>
                            @endif
                        </div>
                        <div class="col-md-4">
                            <h5>{{ __('Question Information') }}</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">{{ __('Question ID:') }}</th>
                                    <td>{{ $question->id }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Slug:') }}</th>
                                    <td>{{ $question->slug }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Is Private:') }}</th>
                                    <td>
                                        @if($question->is_private)
                                        <span class="badge badge-warning">{{ __('Yes') }}</span>
                                        @else
                                        <span class="badge badge-success">{{ __('No') }}</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>{{ __('Replies Count:') }}</th>
                                    <td>{{ $question->replies->count() }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Created At:') }}</th>
                                    <td>{{ $question->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Updated At:') }}</th>
                                    <td>{{ $question->updated_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h5>{{ __('Group Information') }}</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">{{ __('Group ID:') }}</th>
                                    <td>{{ $question->group->id }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Group Name:') }}</th>
                                    <td>{{ $question->group->name }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Group Slug:') }}</th>
                                    <td>{{ $question->group->slug }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Description:') }}</th>
                                    <td>{{ $question->group->description ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Is Private:') }}</th>
                                    <td>
                                        @if($question->group->is_private)
                                        <span class="badge badge-info">{{ __('Yes') }}</span>
                                        @else
                                        <span class="badge badge-secondary">{{ __('No') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h5>{{ __('Author Information') }}</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">{{ __('User ID:') }}</th>
                                    <td>{{ $question->user->id }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Name:') }}</th>
                                    <td>{{ $question->user->name }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Email:') }}</th>
                                    <td>{{ $question->user->email }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Profile:') }}</th>
                                    <td>
                                        @if($question->user->profile)
                                        <img src="{{ $question->user->profile }}" alt="Profile" class="img-thumbnail"
                                            style="width: 50px; height: 50px;">
                                        @else
                                        <span class="text-muted">{{ __('No profile image') }}</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>{{ __('Registered At:') }}</th>
                                    <td>{{ $question->user->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    @if($question->replies->count() > 0)
                    <div class="row mt-4">
                        <div class="col-12">
                            <h5>{{ __('Replies') }} ({{ $question->replies->count() }})</h5>
                            <div class="replies-container">
                                @foreach($question->replies as $reply)
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>{{ $reply->user->name }}</strong>
                                                <small class="text-muted ml-2">{{ $reply->created_at->format('M d, Y
                                                    H:i:s') }}</small>
                                            </div>
                                            <div class="col-md-6 text-right">
                                                @if($reply->user->profile)
                                                <img src="{{ $reply->user->profile }}" alt="Profile"
                                                    class="img-thumbnail" style="width: 30px; height: 30px;">
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
                                <i class="fas fa-info-circle"></i> {{ __('No replies yet for this question.') }}
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
    $(document).ready(function () {
        // Any additional JavaScript can be added here
    });
</script>
@endsection
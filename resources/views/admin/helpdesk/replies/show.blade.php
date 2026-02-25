@extends('layouts.app')

@section('title', __('Reply Details'))

@section('main')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-reply"></i> {{ __('Reply Details') }}
                    </h3>
                    <div class="card-tools">
                        <a href="{{ route('admin.helpdesk.replies.index') }}" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> {{ __('Back to List') }}
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h4>{{ __('Reply Content') }}</h4>
                            <div class="border p-3 bg-light">
                                {!! nl2br(e($reply->reply)) !!}
                            </div>
                            <p class="text-muted mt-2">
                                <i class="fas fa-calendar"></i> {{ $reply->created_at->format('M d, Y H:i:s') }}
                            </p>
                        </div>
                        <div class="col-md-4">
                            <h5>{{ __('Reply Information') }}</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">{{ __('Reply ID:') }}</th>
                                    <td>{{ $reply->id }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Created At:') }}</th>
                                    <td>{{ $reply->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Updated At:') }}</th>
                                    <td>{{ $reply->updated_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h5>{{ __('Question Information') }}</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">{{ __('Question ID:') }}</th>
                                    <td>{{ $reply->question->id }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Question Title:') }}</th>
                                    <td>{{ $reply->question->title }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Question Slug:') }}</th>
                                    <td>{{ $reply->question->slug }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Is Private:') }}</th>
                                    <td>
                                        @if($reply->question->is_private)
                                        <span class="badge badge-warning">{{ __('Yes') }}</span>
                                        @else
                                        <span class="badge badge-success">{{ __('No') }}</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>{{ __('Question Created:') }}</th>
                                    <td>{{ $reply->question->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h5>{{ __('Author Information') }}</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="40%">{{ __('User ID:') }}</th>
                                    <td>{{ $reply->user->id }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Name:') }}</th>
                                    <td>{{ $reply->user->name }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Email:') }}</th>
                                    <td>{{ $reply->user->email }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Profile:') }}</th>
                                    <td>
                                        @if($reply->user->profile)
                                        <img src="{{ $reply->user->profile }}" alt="Profile" class="img-thumbnail"
                                            style="width: 50px; height: 50px;">
                                        @else
                                        <span class="text-muted">{{ __('No profile image') }}</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>{{ __('Registered At:') }}</th>
                                    <td>{{ $reply->user->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-12">
                            <h5>{{ __('Group Information') }}</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="20%">{{ __('Group ID:') }}</th>
                                    <td>{{ $reply->question->group->id }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Group Name:') }}</th>
                                    <td>{{ $reply->question->group->name }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Group Slug:') }}</th>
                                    <td>{{ $reply->question->group->slug }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Description:') }}</th>
                                    <td>{{ $reply->question->group->description ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>{{ __('Is Private:') }}</th>
                                    <td>
                                        @if($reply->question->group->is_private)
                                        <span class="badge badge-info">{{ __('Yes') }}</span>
                                        @else
                                        <span class="badge badge-secondary">{{ __('No') }}</span>
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
    $(document).ready(function () {
        // Any additional JavaScript can be added here
    });
</script>
@endsection
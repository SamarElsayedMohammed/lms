@extends('layouts.app')

@section('title')
    {{ __('Edit Reply') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a class="btn btn-secondary" href="{{ route('admin.helpdesk.replies.index') }}">
            <i class="fas fa-arrow-left"></i> {{ __('Back to List') }}
        </a>
    </div>
@endsection

@section('main')
    <div class="content-wrapper">
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('Edit Reply') }}
                        </h4>

                        <form class="pt-3 mt-6 edit-form" method="POST" 
                              action="{{ route('admin.helpdesk.replies.update', $reply->id) }}" 
                              data-parsley-validate>
                            @csrf
                            @method('PUT')
                            
                            <div class="row">
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="question_id" class="form-label">{{ __('Question') }}</label>
                                    <select name="question_id" id="question_id" class="form-control select2"
                                            data-parsley-required="true">
                                        <option value="">{{ __('Select a Question') }}</option>
                                        @foreach($questions as $question)
                                            <option value="{{ $question->id }}" 
                                                    {{ old('question_id', $reply->question_id) == $question->id ? 'selected' : '' }}>
                                                {{ $question->title }} 
                                                @if($question->group)
                                                    ({{ $question->group->name }})
                                                @endif
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="parent_id" class="form-label">{{ __('Parent Reply') }}</label>
                                    <select name="parent_id" id="parent_id" class="form-control select2">
                                        <option value="">{{ __('None (Top-level reply)') }}</option>
                                        @if($reply->question)
                                            @foreach($reply->question->replies()->where('id', '!=', $reply->id)->get() as $parentReply)
                                                <option value="{{ $parentReply->id }}" 
                                                        {{ old('parent_id', $reply->parent_id) == $parentReply->id ? 'selected' : '' }}>
                                                    {{ \Illuminate\Support\Str::limit($parentReply->reply, 50) }}
                                                </option>
                                            @endforeach
                                        @endif
                                    </select>
                                    <small class="form-text text-muted">{{ __('Select a parent reply if this is a reply to another reply') }}</small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="form-group mandatory col-sm-12">
                                    <label for="reply" class="form-label">{{ __('Reply') }}</label>
                                    <textarea name="reply" id="reply"
                                              class="form-control" 
                                              placeholder="{{ __('Enter your reply') }}" 
                                              rows="8"
                                              data-parsley-required="true">{{ old('reply', $reply->reply) }}</textarea>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h6 class="card-title">{{ __('Reply Information') }}</h6>
                                            <table class="table table-sm table-borderless mb-0">
                                                <tr>
                                                    <th width="20%">{{ __('Reply ID:') }}</th>
                                                    <td>{{ $reply->id }}</td>
                                                </tr>
                                                <tr>
                                                    <th>{{ __('User:') }}</th>
                                                    <td>{{ $reply->user->name ?? 'N/A' }} ({{ $reply->user->email ?? 'N/A' }})</td>
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
                                </div>
                            </div>

                            <div class="form-group mt-4">
                                <input class="btn btn-primary" type="submit" value="{{ __('Update') }}">
                                <a href="{{ route('admin.helpdesk.replies.index') }}" class="btn btn-secondary">
                                    {{ __('Cancel') }}
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script>
$(document).ready(function() {
    // Update parent reply options when question changes
    $('#question_id').on('change', function() {
        const questionId = $(this).val();
        const parentSelect = $('#parent_id');
        
        if (questionId) {
            // Load replies for selected question
            $.get('{{ route("admin.helpdesk.replies.index") }}', {
                question_id: questionId,
                limit: 1000
            }, function(response) {
                if (response && response.rows) {
                    let options = '<option value="">{{ __('None (Top-level reply)') }}</option>';
                    response.rows.forEach(function(reply) {
                        if (reply.id != {{ $reply->id }}) {
                            options += `<option value="${reply.id}">${reply.reply.substring(0, 50)}${reply.reply.length > 50 ? '...' : ''}</option>`;
                        }
                    });
                    parentSelect.html(options);
                }
            });
        } else {
            parentSelect.html('<option value="">{{ __('None (Top-level reply)') }}</option>');
        }
    });
});
</script>
@endsection

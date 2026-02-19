@extends('layouts.app')

@section('title')
    {{ __('Edit Question') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a class="btn btn-secondary" href="{{ route('admin.helpdesk.questions.index') }}">
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
                            {{ __('Edit Question') }}
                        </h4>

                        <form class="pt-3 mt-6 edit-form" method="POST" 
                              action="{{ route('admin.helpdesk.questions.update', $question->id) }}" 
                              data-parsley-validate>
                            @csrf
                            @method('PUT')
                            
                            <div class="row">
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="title" class="form-label">{{ __('Title') }}</label>
                                    <input type="text" name="title" id="title" 
                                           placeholder="{{ __('Question Title') }}" 
                                           class="form-control" 
                                           value="{{ old('title', $question->title) }}"
                                           data-parsley-required="true">
                                </div>

                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="slug" class="form-label">{{ __('Slug') }}</label>
                                    <input type="text" name="slug" id="slug" 
                                           placeholder="{{ __('URL Slug (auto-generated if empty)') }}" 
                                           class="form-control" 
                                           value="{{ old('slug', $question->slug) }}">
                                    <small class="form-text text-muted">{{ __('Leave empty to auto-generate from title') }}</small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="group_id" class="form-label">{{ __('Group') }}</label>
                                    <select name="group_id" id="group_id" class="form-control" data-parsley-required="true">
                                        <option value="">{{ __('Select a Group') }}</option>
                                        @foreach($groups as $group)
                                            <option value="{{ $group->id }}" 
                                                    {{ old('group_id', $question->group_id) == $group->id ? 'selected' : '' }}>
                                                {{ $group->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="form-group col-sm-12 col-md-6">
                                    <label class="form-label">{{ __('Privacy') }}</label>
                                    <div class="custom-switches-stacked mt-2">
                                        <label class="custom-switch">
                                            <input type="hidden" name="is_private" value="0">
                                            <input type="checkbox" name="is_private" value="1" 
                                                   class="custom-switch-input"
                                                   {{ old('is_private', $question->is_private) ? 'checked' : '' }}>
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description">{{ __('Private') }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="form-group mandatory col-sm-12">
                                    <label for="description" class="form-label">{{ __('Description') }}</label>
                                    <textarea name="description" id="description" 
                                              class="form-control" 
                                              placeholder="{{ __('Question Description') }}" 
                                              rows="6"
                                              data-parsley-required="true">{{ old('description', $question->description) }}</textarea>
                                </div>
                            </div>

                            <div class="form-group">
                                <input class="btn btn-primary" type="submit" value="{{ __('Update') }}">
                                <a href="{{ route('admin.helpdesk.questions.index') }}" class="btn btn-secondary">
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

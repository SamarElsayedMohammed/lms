@extends('layouts.app')

@section('title'), 'Edit Chapter')

@section('content')
    <section class="section">
        <div class="section-header">
            <h1>Edit Chapter: {{ $chapter->title }}</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#"> {{ __('Dashboard') }} </a></div>
                <div class="breadcrumb-item"><a href="{{ route('courses.index') }}"> {{ __('Courses') }} </a></div>
                <div class="breadcrumb-item"><a href="{{ route('courses.show', $course->id) }}">{{ $course->name }}</a></div>
                <div class="breadcrumb-item"><a href="{{ route('courses.chapters.index', $course->id) }}"> {{ __('Chapters') }} </a></div>
                <div class="breadcrumb-item"> {{ __('Edit') }} </div>
            </div>
        </div>

        <div class="section-body">
            <h2 class="section-title"> {{ __('Edit Chapter') }} </h2>
            <p class="section-lead"> {{ __('Modify the chapter information below.') }} </p>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4> {{ __('Chapter Information') }} </h4>
                        </div>
                        <div class="card-body">
                            <form
                                action="{{ route('courses.chapters.update', ['course' => $course->id, 'chapter' => $chapter->id]) }}"
                                method="POST"> @csrf
                                @method('PUT')
        <div class="form-group row mb-4">
                                    <label class="col-form-label text-md-right col-12 col-md-3 col-lg-3"> {{ __('Chapter Title') }} <span
                                            class="text-danger"> {{ __('*') }} </span></label>
                                    <div class="col-sm-12 col-md-7">
                                        <input type="text" name="title"
                                            class="form-control @error('title') is-invalid @enderror"
                                            value="{{ old('title', $chapter->title) }}" required> @error(\'title\') <div class="invalid-feedback">{{ $message }}</div> @enderror </div>
                                </div>

                                <div class="form-group row mb-4">
                                    <label class="col-form-label text-md-right col-12 col-md-3 col-lg-3"> {{ __('Description') }} </label>
                                    <div class="col-sm-12 col-md-7">
                                        <textarea name="description" class="form-control @error('description') is-invalid @enderror" style="height: 120px;">{{ old('description', $chapter->description) }}</textarea> @error(\'description\') <div class="invalid-feedback">{{ $message }}</div> @enderror </div>
                                </div>

                                <div class="form-group row mb-4">
                                    <label class="col-form-label text-md-right col-12 col-md-3 col-lg-3"> {{ __('Order') }} </label>
                                    <div class="col-sm-12 col-md-7">
                                        <input type="number" name="order"
                                            class="form-control @error('order') is-invalid @enderror"
                                            value="{{ old('order', $chapter->order) }}" min="0">
                                        <small class="form-text text-muted"> {{ __('The order in which this chapter will appear in the course.') }} </small> @error(\'order\') <div class="invalid-feedback">{{ $message }}</div> @enderror </div>
                                </div>

                                <div class="form-group row mb-4">
                                    <label class="col-form-label text-md-right col-12 col-md-3 col-lg-3"> {{ __('Free
                                        Preview') }} </label>
                                    <div class="col-sm-12 col-md-7">
                                        <label class="custom-switch mt-2">
                                            <input type="checkbox" name="free_preview" value="1"
                                                class="custom-switch-input"
                                                {{ old('free_preview', $chapter->free_preview) ? 'checked' : '' }}>
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description"> {{ __('Allow free preview of this
                                                chapter') }} </span>
                                        </label>
                                    </div>
                                </div>

                                <div class="form-group row mb-4">
                                    <label class="col-form-label text-md-right col-12 col-md-3 col-lg-3"> {{ __('Status') }} </label>
                                    <div class="col-sm-12 col-md-7">
                                        <label class="custom-switch mt-2">
                                            <input type="checkbox" name="is_active" value="1"
                                                class="custom-switch-input"
                                                {{ old('is_active', $chapter->is_active) ? 'checked' : '' }}>
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description"> {{ __('Active') }} </span>
                                        </label>
                                    </div>
                                </div>

                                <div class="form-group row mb-4">
                                    <label class="col-form-label text-md-right col-12 col-md-3 col-lg-3"></label>
                                    <div class="col-sm-12 col-md-7">
                                        <button type="submit" class="btn btn-primary"> {{ __('Update Chapter') }} </button>
                                        <a href="{{ route('courses.chapters.index', $course->id) }}"
                                            class="btn btn-secondary"> {{ __('Cancel') }} </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@extends('layouts.app')

@section('title')
    {{ __('Reorder Curriculum '. ucfirst($curriculum->curriculum_type)) }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('course-chapters.curriculum.index', $curriculum->course_chapter_id) }}">← {{ __('Back to Curriculum') }}</a>
    </div> @endsection

@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container"> {{ __('@switch($curriculum->curriculum_type)
                    @case(\'lecture\')
                        @include(\'courses.chapters.curriculums.types-reorder.lecture\')
                        @break
                    @case(\'quiz\')
                        @include(\'courses.chapters.curriculums.types-reorder.quiz\')
                        @break
                    @case(\'assignment\')
                        @include(\'courses.chapters.curriculums.types-reorder.assignment\')
                        @break
                    @case(\'resource\')
                        @include(\'courses.chapters.curriculums.types-reorder.resource\')
                        @break
                @endswitch') }} </div>
        </div>
    </div>
@endsection

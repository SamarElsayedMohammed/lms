@extends('layouts.app')

@section('title')
    {{ __('manage') . ' ' . __('courses') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('courses.index') }}">← {{ __('Back to All Courses') }}</a>
    </div> @endsection

@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('Course Learnings') }}
                        </h4>

                        {{-- Form start --}}
                        <form class="pt-3 mt-6 create-form" method="POST" data-success-function="formSuccessFunction" action="{{ route('courses.learnings.store', $courseId) }}" data-parsley-validate enctype="multipart/form-data">
                            {{-- Course Learnings --}}
                            <div class="form-group col-12">
                                <label class="form-label">{{ __('Course Learnings') }}</label>
                                <div class="course-learnings-section">
                                    <div data-repeater-list="learnings_data">
                                        <div class="row learning-section d-flex align-items-center mb-2" data-repeater-item>
                                            <input type="hidden" name="id" class="id">
                                            {{-- Learning --}}
                                            <div class="form-group col-md-10">
                                                <label>{{ __('Learning') }} - <span class="learning-number"> {{ __('0') }} </span></label>
                                                <input type="text" name="learning" class="form-control" placeholder="{{ __('Enter a learning outcome') }}" required>
                                            </div>
                                            {{-- Remove Learning --}}
                                            <div class="form-group col-md-2 mt-4">
                                                <button data-repeater-delete type="button" class="btn btn-danger remove-learning" title="{{ __('remove') }}">
                                                    <i class="fa fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    {{-- Add New Learning --}}
                                    <button type="button" class="btn btn-success mt-1" data-repeater-create title="{{ __('Add New Learning') }}">
                                        <i class="fa fa-plus"></i> {{ __('Add New Learning') }}
                                    </button>
                                </div>
                            </div>
                            <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit" value="{{ __('submit') }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div> @endsection
@section('script')
    <script>
        courseLearningsRepeater.setList([
            @foreach($courseLearnings as $key => $learning)
                {
                    id: "{{$learning->id}}",
                    learning: "{{$learning->title}}",
                },
            @endforeach
        ]);

        function formSuccessFunction(response){
            window.location.reload();
        }
    </script>
@endsection

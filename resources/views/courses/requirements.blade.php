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
                            {{ __('Course Requirements') }}
                        </h4>

                        {{-- Form start --}}
                        <form class="pt-3 mt-6 create-form" method="POST" data-success-function="formSuccessFunction" action="{{ route('courses.requirements.store', $courseId) }}" data-parsley-validate enctype="multipart/form-data">
                            {{-- Course Requirements --}}
                            <div class="form-group col-12">
                                <label class="form-label">{{ __('Course Requirements') }}</label>
                                <div class="course-requirements-section">
                                    <div data-repeater-list="requirements_data">
                                        <div class="row learning-section d-flex align-items-center mb-2" data-repeater-item>
                                            <input type="hidden" name="id" class="id">
                                            {{-- Requirement --}}
                                            <div class="form-group col-md-10">
                                                <label>{{ __('Requirement') }} - <span class="requirement-number"> {{ __('0') }} </span></label>
                                                <input type="text" name="requirement" class="form-control" placeholder="{{ __('Enter a requirement') }}" required>
                                            </div>
                                            {{-- Remove Requirement --}}
                                            <div class="form-group col-md-2 mt-4">
                                                <button data-repeater-delete type="button" class="btn btn-danger remove-requirement" title="{{ __('remove') }}">
                                                    <i class="fa fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    {{-- Add New Requirement --}}
                                    <button type="button" class="btn btn-success mt-1" data-repeater-create title="{{ __('Add New Requirement') }}">
                                        <i class="fa fa-plus"></i> {{ __('Add New Requirement') }}
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
        courseRequirementsRepeater.setList([
            @foreach($courseRequirements as $key => $requirement)
                {
                    id: "{{$requirement->id}}",
                    requirement: "{{$requirement->requirement}}",
                },
            @endforeach
        ]);

        function formSuccessFunction(response){
            window.location.reload();
        }
    </script>
@endsection

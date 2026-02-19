@extends('layouts.app')

@section('title')
    {{ __('Become Instructor Settings') }}
@endsection
@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
    </div>
@endsection

@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <form action="{{ route('settings.become-instructor.update') }}" method="POST" class="create-form" data-success-function="formSuccessFunction" id="becomeInstructorForm" enctype="multipart/form-data">
                            @csrf
                            
                            <!-- Main Section -->
                            <h4 class="mb-4">Main Section</h4>
                            <div class="row">
                                <!-- Title -->
                                <div class="col-md-12 mb-4">
                                    <div class="form-group mandatory">
                                        <label for="become_instructor_title" class="form-label">{{ __('Section Title') }}</label>
                                        <input type="text" name="become_instructor_title" id="become_instructor_title" class="form-control" value="{{ $settings['become_instructor_title'] ?? '' }}" required>
                                        <small class="form-text text-muted">{{ __('Main title for the "Become Instructor" section') }}</small>
                                    </div>
                                </div>

                                <!-- Description -->
                                <div class="col-md-12 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_description" class="form-label">{{ __('Description') }}</label>
                                        <textarea name="become_instructor_description" id="become_instructor_description" class="form-control" rows="3">{{ $settings['become_instructor_description'] ?? '' }}</textarea>
                                        <small class="form-text text-muted">{{ __('Description text below the title') }}</small>
                                    </div>
                                </div>

                                <!-- Button Text -->
                                <div class="col-md-6 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_button_text" class="form-label">{{ __('Button Text') }}</label>
                                        <input type="text" name="become_instructor_button_text" id="become_instructor_button_text" class="form-control" value="{{ $settings['become_instructor_button_text'] ?? '' }}" placeholder="e.g., Become an Instructor">
                                        <small class="form-text text-muted">{{ __('Text displayed on the call-to-action button') }}</small>
                                    </div>
                                </div>

                                <!-- Button Link -->
                                <div class="col-md-6 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_button_link" class="form-label">{{ __('Button Link') }}</label>
                                        <input type="text" name="become_instructor_button_link" id="become_instructor_button_link" class="form-control" value="{{ $settings['become_instructor_button_link'] ?? '' }}" placeholder="e.g., /instructor/register">
                                        <small class="form-text text-muted">{{ __('URL where the button should redirect') }}</small>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-5">

                            <!-- Step 1 -->
                            <h4 class="mb-4">Step 1</h4>
                            <div class="row">
                                <div class="col-md-4 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_step_1_image" class="form-label">{{ __('Step 1 Image') }}</label>
                                        <input type="file" name="become_instructor_step_1_image" id="become_instructor_step_1_image" class="form-control" accept="image/*">
                                        @if(!empty($settings['become_instructor_step_1_image']))
                                            <div class="mt-2">
                                                <img src="{{ $settings['become_instructor_step_1_image'] }}" alt="Step 1" style="max-width: 150px; max-height: 150px;">
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-md-4 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_step_1_title" class="form-label">{{ __('Step 1 Title') }}</label>
                                        <input type="text" name="become_instructor_step_1_title" id="become_instructor_step_1_title" class="form-control" value="{{ $settings['become_instructor_step_1_title'] ?? '' }}">
                                    </div>
                                </div>
                                <div class="col-md-4 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_step_1_description" class="form-label">{{ __('Step 1 Description') }}</label>
                                        <textarea name="become_instructor_step_1_description" id="become_instructor_step_1_description" class="form-control" rows="3">{{ $settings['become_instructor_step_1_description'] ?? '' }}</textarea>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Step 2 -->
                            <h4 class="mb-4">Step 2</h4>
                            <div class="row">
                                <div class="col-md-4 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_step_2_image" class="form-label">{{ __('Step 2 Image') }}</label>
                                        <input type="file" name="become_instructor_step_2_image" id="become_instructor_step_2_image" class="form-control" accept="image/*">
                                        @if(!empty($settings['become_instructor_step_2_image']))
                                            <div class="mt-2">
                                                <img src="{{ $settings['become_instructor_step_2_image'] }}" alt="Step 2" style="max-width: 150px; max-height: 150px;">
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-md-4 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_step_2_title" class="form-label">{{ __('Step 2 Title') }}</label>
                                        <input type="text" name="become_instructor_step_2_title" id="become_instructor_step_2_title" class="form-control" value="{{ $settings['become_instructor_step_2_title'] ?? '' }}">
                                    </div>
                                </div>
                                <div class="col-md-4 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_step_2_description" class="form-label">{{ __('Step 2 Description') }}</label>
                                        <textarea name="become_instructor_step_2_description" id="become_instructor_step_2_description" class="form-control" rows="3">{{ $settings['become_instructor_step_2_description'] ?? '' }}</textarea>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Step 3 -->
                            <h4 class="mb-4">Step 3</h4>
                            <div class="row">
                                <div class="col-md-4 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_step_3_image" class="form-label">{{ __('Step 3 Image') }}</label>
                                        <input type="file" name="become_instructor_step_3_image" id="become_instructor_step_3_image" class="form-control" accept="image/*">
                                        @if(!empty($settings['become_instructor_step_3_image']))
                                            <div class="mt-2">
                                                <img src="{{ $settings['become_instructor_step_3_image'] }}" alt="Step 3" style="max-width: 150px; max-height: 150px;">
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-md-4 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_step_3_title" class="form-label">{{ __('Step 3 Title') }}</label>
                                        <input type="text" name="become_instructor_step_3_title" id="become_instructor_step_3_title" class="form-control" value="{{ $settings['become_instructor_step_3_title'] ?? '' }}">
                                    </div>
                                </div>
                                <div class="col-md-4 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_step_3_description" class="form-label">{{ __('Step 3 Description') }}</label>
                                        <textarea name="become_instructor_step_3_description" id="become_instructor_step_3_description" class="form-control" rows="3">{{ $settings['become_instructor_step_3_description'] ?? '' }}</textarea>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Step 4 -->
                            <h4 class="mb-4">Step 4</h4>
                            <div class="row">
                                <div class="col-md-4 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_step_4_image" class="form-label">{{ __('Step 4 Image') }}</label>
                                        <input type="file" name="become_instructor_step_4_image" id="become_instructor_step_4_image" class="form-control" accept="image/*">
                                        @if(!empty($settings['become_instructor_step_4_image']))
                                            <div class="mt-2">
                                                <img src="{{ $settings['become_instructor_step_4_image'] }}" alt="Step 4" style="max-width: 150px; max-height: 150px;">
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-md-4 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_step_4_title" class="form-label">{{ __('Step 4 Title') }}</label>
                                        <input type="text" name="become_instructor_step_4_title" id="become_instructor_step_4_title" class="form-control" value="{{ $settings['become_instructor_step_4_title'] ?? '' }}">
                                    </div>
                                </div>
                                <div class="col-md-4 mb-4">
                                    <div class="form-group">
                                        <label for="become_instructor_step_4_description" class="form-label">{{ __('Step 4 Description') }}</label>
                                        <textarea name="become_instructor_step_4_description" id="become_instructor_step_4_description" class="form-control" rows="3">{{ $settings['become_instructor_step_4_description'] ?? '' }}</textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-4">
                                <div class="col-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary me-1 mb-1" id="submitBtn">{{ __('Save Settings') }}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    function formSuccessFunction() {
        window.location.reload();
    }
</script>
@endpush

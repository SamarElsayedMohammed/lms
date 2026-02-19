@extends('layouts.app')

@section('title')
    {{ __('Why Choose Us Settings') }}
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
                        <form action="{{ route('settings.why-choose-us.update') }}" method="POST" class="create-form" data-success-function="formSuccessFunction" id="whyChooseUsForm" enctype="multipart/form-data">
                            @csrf
                            <div class="row">
                                <!-- Image -->
                                <div class="col-md-6 mb-4">
                                    <div class="form-group">
                                        <label for="why_choose_us_image" class="form-label">{{ __('Section Image') }}</label>
                                        <input type="file" name="why_choose_us_image" id="why_choose_us_image" class="form-control" accept="image/jpeg,image/png,image/jpg,image/svg+xml,image/webp">
                                        <small class="form-text text-muted">{{ __('Image for the "Why Choose Us" section (Max: 2MB)') }}</small>
                                        @if(!empty($settings['why_choose_us_image']))
                                            <div class="mt-2">
                                                <img src="{{ $settings['why_choose_us_image'] }}" alt="Current Image" style="max-width: 200px; max-height: 200px; object-fit: cover;">
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <!-- Title -->
                                <div class="col-md-6 mb-4">
                                    <div class="form-group mandatory">
                                        <label for="why_choose_us_title" class="form-label">{{ __('Section Title') }}</label>
                                        <input type="text" name="why_choose_us_title" id="why_choose_us_title" class="form-control" value="{{ $settings['why_choose_us_title'] ?? '' }}" required>
                                        <small class="form-text text-muted">{{ __('Main title for the "Why Choose Us" section') }}</small>
                                    </div>
                                </div>

                                <!-- Description -->
                                <div class="col-12 mb-4">
                                    <div class="form-group">
                                        <label for="why_choose_us_description" class="form-label">{{ __('Description') }}</label>
                                        <textarea name="why_choose_us_description" id="why_choose_us_description" class="form-control" rows="3">{{ $settings['why_choose_us_description'] ?? '' }}</textarea>
                                        <small class="form-text text-muted">{{ __('Description text below the title') }}</small>
                                    </div>
                                </div>

                                <!-- Point 1 -->
                                <div class="col-12 mb-4">
                                    <div class="form-group">
                                        <label for="why_choose_us_point_1" class="form-label">{{ __('Point 1') }}</label>
                                        <input type="text" name="why_choose_us_point_1" id="why_choose_us_point_1" class="form-control" value="{{ $settings['why_choose_us_point_1'] ?? '' }}">
                                        <small class="form-text text-muted">{{ __('First benefit/feature point') }}</small>
                                    </div>
                                </div>

                                <!-- Point 2 -->
                                <div class="col-12 mb-4">
                                    <div class="form-group">
                                        <label for="why_choose_us_point_2" class="form-label">{{ __('Point 2') }}</label>
                                        <input type="text" name="why_choose_us_point_2" id="why_choose_us_point_2" class="form-control" value="{{ $settings['why_choose_us_point_2'] ?? '' }}">
                                        <small class="form-text text-muted">{{ __('Second benefit/feature point') }}</small>
                                    </div>
                                </div>

                                <!-- Point 3 -->
                                <div class="col-12 mb-4">
                                    <div class="form-group">
                                        <label for="why_choose_us_point_3" class="form-label">{{ __('Point 3') }}</label>
                                        <input type="text" name="why_choose_us_point_3" id="why_choose_us_point_3" class="form-control" value="{{ $settings['why_choose_us_point_3'] ?? '' }}">
                                        <small class="form-text text-muted">{{ __('Third benefit/feature point') }}</small>
                                    </div>
                                </div>

                                <!-- Point 4 -->
                                <div class="col-12 mb-4">
                                    <div class="form-group">
                                        <label for="why_choose_us_point_4" class="form-label">{{ __('Point 4') }}</label>
                                        <input type="text" name="why_choose_us_point_4" id="why_choose_us_point_4" class="form-control" value="{{ $settings['why_choose_us_point_4'] ?? '' }}">
                                        <small class="form-text text-muted">{{ __('Fourth benefit/feature point') }}</small>
                                    </div>
                                </div>

                                <!-- Point 5 -->
                                <div class="col-12 mb-4">
                                    <div class="form-group">
                                        <label for="why_choose_us_point_5" class="form-label">{{ __('Point 5') }}</label>
                                        <input type="text" name="why_choose_us_point_5" id="why_choose_us_point_5" class="form-control" value="{{ $settings['why_choose_us_point_5'] ?? '' }}">
                                        <small class="form-text text-muted">{{ __('Fifth benefit/feature point') }}</small>
                                    </div>
                                </div>

                                <!-- Button Text -->
                                <div class="col-md-6 mb-4">
                                    <div class="form-group">
                                        <label for="why_choose_us_button_text" class="form-label">{{ __('Button Text') }}</label>
                                        <input type="text" name="why_choose_us_button_text" id="why_choose_us_button_text" class="form-control" value="{{ $settings['why_choose_us_button_text'] ?? '' }}" placeholder="e.g., Join for Free">
                                        <small class="form-text text-muted">{{ __('Text displayed on the call-to-action button') }}</small>
                                    </div>
                                </div>

                                <!-- Button Link -->
                                <div class="col-md-6 mb-4">
                                    <div class="form-group">
                                        <label for="why_choose_us_button_link" class="form-label">{{ __('Button Link') }}</label>
                                        <input type="text" name="why_choose_us_button_link" id="why_choose_us_button_link" class="form-control" value="{{ $settings['why_choose_us_button_link'] ?? '' }}" placeholder="e.g., /register">
                                        <small class="form-text text-muted">{{ __('URL where the button should redirect (relative or absolute)') }}</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
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

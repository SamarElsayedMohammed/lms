@extends('layouts.app')

@section('title')
    {{ __('App Settings') }}
@endsection
@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
    </div> @endsection

@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <form action="{{ route('settings.app.update') }}" method="POST" enctype="multipart/form-data" class="create-form" data-success-function="formSuccessFunction"> @csrf <div class="row">
                                {{-- Playstore URL --}}
                                <div class="form-group col-sm-12 col-md-6 col-lg-4">
                                    <label for="playstore-url" class="form-label">{{ __('Playstore URL') }}</label>
                                    <input type="text" name="playstore_url" class="form-control" id="playstore-url" value="{{ $settings['playstore_url'] ?? '' }}" placeholder="{{ __('Playstore URL') }}">
                                </div>

                                {{-- Appstore URL --}}
                                <div class="form-group col-sm-12 col-md-6 col-lg-4">
                                    <label for="appstore-url" class="form-label">{{ __('Appstore URL') }}</label>
                                    <input type="text" name="appstore_url" class="form-control" id="appstore-url" value="{{ $settings['appstore_url'] ?? '' }}" placeholder="{{ __('Appstore URL') }}">
                                </div>

                                {{-- Android Version --}}
                                <div class="form-group col-sm-12 col-md-6 col-lg-4">
                                    <label for="android-version" class="form-label">{{ __('Android Version') }}</label>
                                    <input type="text" name="android_version" class="form-control" id="android-version" value="{{ $settings['android_version'] ?? '' }}" placeholder="{{ __('Android Version') }}">
                                </div>

                                {{-- iOS Version --}}
                                <div class="form-group col-sm-12 col-md-6 col-lg-4">
                                    <label for="ios-version" class="form-label">{{ __('iOS Version') }}</label>
                                    <input type="text" name="ios_version" class="form-control" id="ios-version" value="{{ $settings['ios_version'] ?? '' }}" placeholder="{{ __('iOS Version') }}">
                                </div>

                                {{-- App Version --}}
                                <div class="form-group col-sm-12 col-md-6 col-lg-4">
                                    <label for="app-version" class="form-label">{{ __('App Version') }}</label>
                                    <input type="text" name="app_version" class="form-control" id="app-version" value="{{ $settings['app_version'] ?? '' }}" placeholder="{{ __('App Version') }}">
                                </div>

                                {{-- Force Update --}}
                                <div class="form-group col-sm-12 col-md-6 col-lg-4">
                                    <div class="control-label">{{ __('Force Update') }}</div>
                                    <div class="custom-switches-stacked mt-2">
                                        <label class="custom-switch">
                                            <input type="checkbox" name="force_update" value="1" class="custom-switch-input" id="force-update">
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description">{{ __('Enable') }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-4">
                                {{-- Save Button --}}
                                <div class="col-12 text-right">
                                    <button class="btn btn-primary" id="save-btn">{{ __('Update') }}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div> @endsection

@push('scripts')
<script>
        $(document).ready(function() {
            let forceUpdate = {{ $settings['force_update'] ?? 0 }};
            if(forceUpdate == 1) {
                $('#force-update').prop('checked', true).trigger('change');
            }
        });
        function formSuccessFunction() {
            location.reload();
        }
    </script>
@endpush

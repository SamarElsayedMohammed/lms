@extends('layouts.app')

@section('title')
    {{ __('Firebase Settings') }}
@endsection
@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
    </div> @endsection

@section('main')
    <div class="content-wrapper">
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <div class="mb-4">
                            <h5 class="mb-3">{{ __('Status') }}</h5>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="badge {{ ($firebaseHealth['client_config_complete'] ?? false) ? 'badge-success' : 'badge-danger' }}">
                                    {{ __('Client Config') }}: {{ ($firebaseHealth['client_config_complete'] ?? false) ? 'OK' : 'Incomplete' }}
                                </span>
                                <span class="badge {{ ($firebaseHealth['server_credentials_present'] ?? false) ? 'badge-success' : 'badge-danger' }}">
                                    {{ __('Server Credentials') }}: {{ ($firebaseHealth['server_credentials_present'] ?? false) ? 'OK' : 'Missing' }}
                                </span>
                                <span class="badge {{ ($firebaseHealth['fcm_ready'] ?? false) ? 'badge-success' : 'badge-warning' }}">
                                    FCM: {{ ($firebaseHealth['fcm_ready'] ?? false) ? 'OK' : 'Not Ready' }}
                                </span>
                            </div>
                            <small class="form-text text-muted d-block mt-2">
                                {{ __('Public API') }}: <code>{{ url('/api/firebase-config') }}</code>
                            </small>
                        </div>

                        <form action="{{ route('settings.firebase.update') }}" method="POST" class="create-form" data-success-function="formSuccessFunction" enctype="multipart/form-data">
                            @csrf

                            <div class="row">
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group mandatory">
                                        <label for="firebase_api_key" class="form-label">apiKey</label>
                                        <input type="text" name="firebase_api_key" class="form-control" id="firebase_api_key" value="{{ $settings['firebase_api_key'] ?? '' }}" placeholder="AIza...">
                                    </div>
                                </div>
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group mandatory">
                                        <label for="firebase_auth_domain" class="form-label">authDomain</label>
                                        <input type="text" name="firebase_auth_domain" class="form-control" id="firebase_auth_domain" value="{{ $settings['firebase_auth_domain'] ?? '' }}" placeholder="project-id.firebaseapp.com">
                                    </div>
                                </div>
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group mandatory">
                                        <label for="firebase_project_id" class="form-label">{{ __('projectId') }}</label>
                                        <input type="text" name="firebase_project_id" class="form-control" id="firebase_project_id" value="{{ $settings['firebase_project_id'] ?? '' }}" placeholder="{{ __('Firebase Project ID') }}">
                                    </div>
                                </div>
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="firebase_storage_bucket" class="form-label">storageBucket</label>
                                        <input type="text" name="firebase_storage_bucket" class="form-control" id="firebase_storage_bucket" value="{{ $settings['firebase_storage_bucket'] ?? '' }}" placeholder="project-id.appspot.com">
                                    </div>
                                </div>
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="firebase_messaging_sender_id" class="form-label">messagingSenderId</label>
                                        <input type="text" name="firebase_messaging_sender_id" class="form-control" id="firebase_messaging_sender_id" value="{{ $settings['firebase_messaging_sender_id'] ?? '' }}">
                                    </div>
                                </div>
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group mandatory">
                                        <label for="firebase_app_id" class="form-label">appId</label>
                                        <input type="text" name="firebase_app_id" class="form-control" id="firebase_app_id" value="{{ $settings['firebase_app_id'] ?? '' }}" placeholder="1:...:web:...">
                                    </div>
                                </div>
                                <div class="col-lg-6 col-md-6 col-sm-12">
                                    <div class="form-group">
                                        <label for="firebase_measurement_id" class="form-label">measurementId</label>
                                        <input type="text" name="firebase_measurement_id" class="form-control" id="firebase_measurement_id" value="{{ $settings['firebase_measurement_id'] ?? '' }}">
                                    </div>
                                </div>
                            </div>

                            <div class="row col-12">
                                <div class="form-group">
                                    <label for="firebase_service_file" class="form-label">{{ __('Firebase Service Account JSON File') }}</label>
                                    <div class="custom-file">
                                        <input type="file" class="custom-file-input" id="firebase_service_file" name="firebase_service_file" accept=".json">
                                        <label class="custom-file-label" for="firebase_service_file">{{ __('Choose file') }}</label>
                                    </div>
                                    <small class="form-text text-muted">
                                        {{ __('Upload the Firebase service account JSON file downloaded from Firebase Console > Project Settings > Service accounts > Generate new private key.') }}
                                    </small>
                                    @if(isset($firebaseServiceFileExists) && $firebaseServiceFileExists)
                                        <div class="mt-2">
                                            <span class="badge badge-success">{{ __('Firebase service account file is uploaded.') }}</span>
                                        </div>
                                    @else
                                        <div class="mt-2">
                                            <span class="badge badge-warning">{{ __('Firebase service account file is not uploaded.') }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit" value="{{ __('submit') }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div> @endsection

@push('scripts')
    <script>
        function formSuccessFunction(response) {
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }
    </script>
@endpush

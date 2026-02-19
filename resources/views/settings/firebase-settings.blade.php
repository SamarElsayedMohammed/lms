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
        <!-- Create Form -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <form action="{{ route('settings.firebase.update') }}" method="POST" class="create-form" data-success-function="formSuccessFunction" enctype="multipart/form-data"> @csrf <!-- Firebase Project ID -->
                            <div class="col-lg-6 col-md-6 col-sm-12">
                                <div class="form-group mandatory">
                                    <label for="firebase_project_id" class="form-label">{{ __('projectId') }}</label>
                                    <input type="text" name="firebase_project_id" class="form-control" id="firebase_project_id" value="{{ $settings['firebase_project_id'] ?? '' }}" placeholder="{{ __('Firebase Project ID') }}" required>
                                </div>
                            </div>

                            <!-- Firebase Service Account File -->
                            <div class="row col-12">
                                <div class="form-group">
                                    <label for="firebase_service_file" class="form-label">{{ __('Firebase Service Account JSON File') }}</label>
                                    <div class="custom-file">
                                        <input type="file" class="custom-file-input" id="firebase_service_file" name="firebase_service_file" accept=".json">
                                        <label class="custom-file-label" for="firebase_service_file">{{ __('Choose file') }}</label>
                                    </div>
                                    <small class="form-text text-muted">
                                        {{ __('Upload the Firebase service account JSON file downloaded from Firebase Console > Project Settings > Service accounts > Generate new private key.') }}
                                    </small> @if(isset($firebaseServiceFileExists) && $firebaseServiceFileExists) <div class="mt-2">
                                            <span class="badge badge-success">{{ __('Firebase service account file is uploaded.') }}</span>
                                            @php
                                                // $filePath = $settings['firebase_service_file'];
                                                // $filePerms = fileperms($filePath);
                                                // $isReadable = is_readable($filePath);
                                                // $fileSize = filesize($filePath);
                                                // $fileOwner = fileowner($filePath);
                                                // $ownerInfo = posix_getpwuid($fileOwner);
                                            @endphp
                                            {{-- <div class="mt-1 small">
                                                <p class="mb-0"><strong> {{ __('File Info:') }} </strong></p>
                                                <ul class="pl-3 mb-0">
                                                    <li> {{ __('Readable:') }} <span class="{{ $isReadable ? 'text-success' : 'text-danger' }}">{{ $isReadable ? 'Yes' : 'No' }}</span></li>
                                                    <li>Permissions: {{ substr(sprintf('%o', $filePerms), -4) }}</li>
                                                    <li>Size: {{ round($fileSize / 1024, 2) }} KB</li>
                                                    <li>Owner: {{ $ownerInfo['name'] ?? 'Unknown' }}</li>
                                                </ul>
                                            </div> --}}
                                        </div> @else <div class="mt-2">
                                            <span class="badge badge-warning">{{ __('Firebase service account file is not uploaded.') }}</span>
                                        </div> @endif </div>
                            </div>

                            <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit" value="{{ __('submit') }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div> @endsection

@push('scripts')
<!-- JS Libraries -->
    <script>
        function formSuccessFunction(response) {
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }
    </script>
@endpush

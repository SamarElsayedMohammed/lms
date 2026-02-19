@extends('layouts.app')

@section('title')
    {{ __('Profile') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
@endsection

@section('main')
    <div class="content-wrapper">
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">{{ __('Update Profile') }}</h4>
                        <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('admin.profile.update') }}" enctype="multipart/form-data" data-parsley-validate>
                            @csrf
                            <div class="row">
                                <div class="form-group col-sm-12 col-md-6">
                                    <label>{{ __('Name') }} <span class="text-danger"> * </span></label>
                                    <input type="text" name="name" placeholder="{{ __('Name') }}" class="form-control mb-3" value="{{ old('name', $user->name) }}" required>
                                </div>

                                <div class="form-group col-sm-12 col-md-6">
                                    <label>{{ __('Email') }} <span class="text-danger"> * </span></label>
                                    <input type="email" name="email" placeholder="{{ __('Email') }}" class="form-control mb-3" value="{{ old('email', $user->email) }}" required>
                                </div>

                                <div class="form-group col-sm-12 col-md-6">
                                    <label>{{ __('Mobile') }}</label>
                                    <input type="text" name="mobile" placeholder="{{ __('Mobile') }}" class="form-control mb-3" value="{{ old('mobile', $user->mobile) }}">
                                </div>

                                <div class="form-group col-sm-12 col-md-6">
                                    <label>{{ __('Profile Image') }}</label>
                                    <input type="file" name="profile" class="form-control-file mb-3" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp,image/svg+xml">
                                    @if($user->profile)
                                        <div class="mt-2">
                                            <img src="{{ $user->profile }}" alt="Profile" class="img-thumbnail" style="max-width: 150px; max-height: 150px;">
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <hr class="my-4">
                            <h5 class="mb-3">{{ __('Change Password') }}</h5>
                            <p class="text-muted">{{ __('Leave blank if you don\'t want to change password') }}</p>

                            <div class="row">
                                <div class="form-group col-sm-12 col-md-4">
                                    <label>{{ __('Current Password') }}</label>
                                    <input type="password" name="current_password" placeholder="{{ __('Current Password') }}" class="form-control mb-3">
                                </div>

                                <div class="form-group col-sm-12 col-md-4">
                                    <label>{{ __('New Password') }}</label>
                                    <input type="password" name="password" id="password" placeholder="{{ __('New Password') }}" class="form-control mb-3" minlength="8">
                                </div>

                                <div class="form-group col-sm-12 col-md-4">
                                    <label>{{ __('Confirm Password') }}</label>
                                    <input type="password" name="password_confirmation" placeholder="{{ __('Confirm Password') }}" class="form-control mb-3" data-parsley-equalto="#password">
                                </div>
                            </div>

                            <input class="btn btn-primary float-right ml-3" type="submit" value="{{ __('Update Profile') }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

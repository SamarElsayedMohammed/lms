@extends('layouts.app')

@section('title')
    {{ __('Show Role') }}
@endsection

@section('page-title')
    <h1 class="mb-0">
        {{ __('Show Role') }}
    </h1>

    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('roles.index') }}">← {{ __('Back To Roles') }}</a>
    </div> @endsection

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="mb-4">
                        <label class="form-label fw-bold">{{ __('Role') }}:</label>
                        <p>{{ $role->name }}</p>
                    </div>

                    <div>
                        <label class="form-label fw-bold">{{ __('Permissions') }}:</label>
                        <div class="row"> @if(!empty($rolePermissions) && count($rolePermissions) > 0)
                                @foreach($rolePermissions as $permission) <div class="col-md-3 col-sm-4 col-6 mb-2">
                                        <span class="badge bg-success text-white px-3 py-2">{{ $permission->name }}</span>
                                    </div> @endforeach
                            @else <div class="col-12">
                                    <p class="text-muted">{{ __('No permissions assigned') }}</p>
                                </div> @endif </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
@endsection

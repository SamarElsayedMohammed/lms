@extends('layouts.app')

@section('title')
    {{ __('Create New Role') }}
@endsection

@section('page-title')
    <h1 class="mb-0">
        {{ __('Create New Role') }}
    </h1>

    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('roles.index') }}">← {{ __('Back To Roles') }}</a>
    </div> @endsection

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form class="pt-3 mt-6 create-form" method="POST" data-success-function="formSuccessFunction" action="{{ route('roles.store') }}" data-parsley-validate> @csrf
                        @method('POST')
        <div class="row">
                        <div class="col-md-12">
                            <div class="form-group col-sm-12 col-md-12">
                                <label for="name" class="form-label mandatory">{{ __('Name') }}</label> <span class="text-danger">*</span>
                                <input type="text" name="name" id="name" placeholder="{{ __('Name') }}" class="form-control" data-parsley-required="true">
                            </div>
                        </div>
                        <div id="permission-list"></div>
                        <div class="permission-tree ms-5 my-3">
                            <ul>
                                @if(isset($groupedPermissions) && !empty($groupedPermissions))
                                    @foreach ($groupedPermissions as $groupName => $groupData)
                                        @if(is_array($groupData) || is_object($groupData))
                                            <li data-jstree='{"opened":true}'>
                                                @php
                                                    $groupNameStr = is_string($groupName) ? $groupName : (is_array($groupName) ? json_encode($groupName) : '');
                                                @endphp
                                                {{ ucwords(str_replace('-', ' ', $groupNameStr)) }}
                                                @foreach ($groupData as $permission)
                                                    @if(is_object($permission) && isset($permission->id))
                                                        @php
                                                            $permissionId = is_scalar($permission->id) ? $permission->id : '';
                                                            $permissionName = is_string($permission->name ?? null) ? $permission->name : (is_scalar($permission->name ?? null) ? (string)$permission->name : '');
                                                            $shortName = is_string($permission->short_name ?? null) ? $permission->short_name : (is_string($permission->name ?? null) ? $permission->name : '');
                                                        @endphp
                                                        <ul>
                                                            <li id="{{ $permissionId }}" data-name="{{ $permissionName }}"
                                                                data-jstree='{"icon":"fa fa-user-cog"}'>
                                                                {{ ucfirst($shortName) }}
                                                            </li>
                                                        </ul>
                                                    @endif
                                                @endforeach
                                            </li>
                                        @endif
                                    @endforeach
                                @else
                                    <li>{{ __('No permissions available') }}</li>
                                @endif
                            </ul>
                        </div>
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary">{{ __('Submit') }}</button>
                        </div>
                    </div>
                    </form>
                </div>
            </div>
        </div>
    </div> @endsection
@section('js')
    <script>
        function successFunction() {
            $('.permission-tree').jstree(true).deselect_all();
        }
        function formSuccessFunction(response) {
            setTimeout(() => {
                location.reload();
            }, 2000);
        }
    </script>
@endsection

@extends('layouts.app')

@section('title')
    {{ __('Edit Role') }}
@endsection

@section('page-title')
    <h1 class="mb-0">
        {{ __('Edit Role') }}
    </h1>

    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('roles.index') }}">← {{ __('Back To Roles') }}</a>
    </div> @endsection

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form class="pt-3 mt-6 edit-form" method="POST" data-success-function="formSuccessFunction"
                        action="{{ route('roles.update', $role->id) }}" data-parsley-validate> @csrf
                        @method('PATCH')
        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group col-sm-12 col-md-12">
                                    <label for="name" class="form-label mandatory">{{ __('name') }}</label> <span class="text-danger"> * </span>
                                    <input type="text" name="name" id="name" placeholder="{{ __('name') }}" class="form-control" data-parsley-required="true"
                                        value="{{ old('name', $role->name) }}">
                                </div>
                            </div>

                            <div id="permission-list"></div>

                            <div class="permission-tree ms-5 my-3">
                                <ul> @foreach ($groupedPermissions as $groupName => $groupData) <li data-jstree='{"opened":true}'>{{ ucwords(str_replace('-', ' ', $groupName)) }}
                                            @foreach ($groupData as $permission)
                                                <ul>
                                                    <li id="{{ $permission->id }}" data-name="{{ $permission->name }}"
                                                        data-jstree='{
                                                            "icon":"fa fa-user-cog",
                                                            "selected": {{ in_array($permission->id, $rolePermissions) ? 'true' : 'false' }},
                                                            "expand_selected_onload": true
                                                        }'>
                                                        {{ ucfirst($permission->short_name) }}
                                                    </li>
                                                </ul> @endforeach </li> @endforeach </ul>
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

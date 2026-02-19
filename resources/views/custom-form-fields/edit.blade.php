@extends('layouts.app')

@section('title')
    {{ __('edit') . ' ' . __('custom-fields') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a href="{{ route('custom-form-fields.index') }}" class="btn btn-primary">← {{ __('Back To Custom Fields') }}</a>

    </div> @endsection

@section('main')
    <div class="content-wrapper">
        {{-- <div class="page-header">
            <h3 class="page-title">
                {{ __('edit') . ' ' . __('custom-fields') }}
            </h3>
        </div> --}}
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('edit') . ' ' . __('custom-fields') }}
                        </h4>
                        <form class="pt-3 edit-form edit-common-validation-rules"
                            action="{{ route('custom-form-fields.update', $customField->id) }}" method="POST" data-success-function="formSuccessFunction"
                            data-parsley-validate>
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="edit_id" id="edit-id" value="{{ $customField->id }}" />
                            <div class="row">
                                <div class="form-group col-sm-12 col-md-6">
                                    <label>{{ __('name') }} <span class="text-danger"> * </span></label>
                                    <input type="text" name="name" onkeypress="validateInput(event)" id="edit-name"
                                        placeholder="{{ __('name') }}" class="form-control"
                                        value="{{ old('name', $customField->name) }}" required>
                                </div>
                                <div class="form-group col-sm-12 col-md-6">
                                    <label>{{ __('type') }} <span class="text-danger"> * </span></label>
                                    <select name="type" id="edit-type-select" class="form-control edit-type-field"
                                        required disabled>
                                        <option value="number" {{ $customField->type === 'number' ? 'selected' : '' }}>
                                            {{ __('Numeric') }}</option>
                                        <option value="text" {{ $customField->type === 'text' ? 'selected' : '' }}>
                                            {{ __('Text') }}</option>
                                        <option value="file" {{ $customField->type === 'file' ? 'selected' : '' }}>
                                            {{ __('File Upload') }}</option>
                                        <option value="radio" {{ $customField->type === 'radio' ? 'selected' : '' }}>
                                            {{ __('Radio Button') }}</option>
                                        <option value="dropdown" {{ $customField->type === 'dropdown' ? 'selected' : '' }}>
                                            {{ __('Dropdown') }}</option>
                                        <option value="checkbox" {{ $customField->type === 'checkbox' ? 'selected' : '' }}>
                                            {{ __('Checkbox') }}</option>
                                    </select>
                                </div>
                                <div class="form-group col-sm-12 col-md-2">
                                    <label class="d-block">{{ __('required') }}</label>
                                    {{-- <div class="custom-control custom-switch">
                                        <input type="checkbox" class="custom-control-input" name="edit-required" id="edit-required" value="1" {{ $customField->is_required ? 'checked' : '' }}>
                                        <input type="hidden" name="required" value="0">
                                        <label class="custom-control-label" for="edit-required">{{ __('Yes') }}</label>
                                    </div> --}}

                                    <div class="custom-switches-stacked mt-2">
                                        <label class="custom-switch" for="edit-required">
                                            <input type="checkbox" name="edit-required" id="edit-required" value="1" class="custom-switch-input required-field" {{ $customField->is_required ? 'checked' : '' }}>
                                            <input type="hidden" name="required" value="0">
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description">{{ __('Active') }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="type" value="{{ $customField->type }}">
                            <div class="edit-default-values-section"
                                style="display: {{ in_array($customField->type, ['radio', 'dropdown', 'checkbox']) ? 'block' : 'none' }};">
                                <div class="mt-4" data-repeater-list="edit_default_data">
                                    <div class="mb-3">
                                        <button type="button" class="btn btn-success add-new-edit-option"
                                            data-repeater-create title="{{ __('add_new_option') }}">
                                            <span><i class="fa fa-plus"></i> {{ __('add_new_option') }}</span>
                                        </button>
                                    </div>

                                    <div class="row edit-option-section" data-repeater-item>
                                        <input type="hidden" name="default_value_id" class="default_value_id"/>
                                        <div class="form-group col-md-5">
                                            <label>{{ __('option') }} - <span class="edit-option-number"></span> <span
                                                    class="text-danger"> * </span></label>
                                            <input type="text" name="option" placeholder="{{ __('text') }}"
                                                class="form-control" required>
                                        </div>
                                        <div class="form-group col-md-1 pl-0 mt-4">
                                            <button data-repeater-delete type="button"
                                                class="btn btn-icon bg-danger text-white remove-edit-default-option"
                                                title="{{ __('remove') . ' ' . __('option') }}"
                                                {{ count($defaultValues) <= 2 ? 'disabled' : '' }}>
                                                <i class="fa fa-times"></i>
                                            </button>
                                        </div>
                                    </div>

                                </div>
                            </div>
                            <div class="mt-4">
                                <input class="btn btn-primary ml-2" type="submit" value="{{ __('submit') }}" />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
         function formSuccessFunction(response) {
            setTimeout(() => {
                location.reload();
            }, 1000);
        }
        $(document).ready(function() {
            window.validateInput = function(event) {
                var charCode = event.which || event.keyCode;
                if (!(charCode >= 65 && charCode <= 90) && !(charCode >= 97 && charCode <= 122) && !(
                        charCode === 32)) {
                    event.preventDefault();
                }
            };
        });
        editDefaultValuesRepeater.setList([
            @foreach ($defaultValues as $data)
                    {
                        default_value_id: "{{ $data['id'] ?? '' }}",
                        option: "{{ addslashes($data['option']) }}"
                    }@if (!$loop->last),@endif
                @endforeach 
        ]);
        
        // Initialize delete button states on page load
        $(document).ready(function() {
            editToggleAccessOfDeleteButtons();
        });
        // $('.file_type').trigger('change');
    </script>
@endsection

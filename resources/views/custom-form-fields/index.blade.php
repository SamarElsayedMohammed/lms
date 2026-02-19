@extends('layouts.app')

@section('title')
    {{ __('manage') . ' ' . __('custom-fields') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
    </div>
@endsection

@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        @can('custom-form-fields-create')
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('create') . ' ' . __('custom-fields') }}

                        </h4>
                        <form class="pt-3 mt-6 create-form" method="POST" data-success-function="formSuccessFunction"
                            action="{{ route('custom-form-fields.store') }}" data-parsley-validate enctype="multipart/form-data"> @csrf <div class="row">
                                <div class="form-group col-sm-12 col-md-5">
                                    <label>{{ __('name') }} <span class="text-danger"> * </span></label>
                                    <input type="text" name="name" id="name" onkeypress="validateInput(event)"
                                        placeholder="{{ __('name') }}" class="form-control" required>
                                </div>
                                <div class="form-group col-sm-12 col-md-5">
                                    <label>{{ __('type') }} <span class="text-danger"> * </span></label>
                                    <select name="type" id="type-field" class="form-control type-field" required>
                                        <option value="number">{{ __('Numeric') }}</option>
                                        <option value="text" selected>{{ __('Text') }}</option>
                                        <option value="file">{{ __('File Upload') }}</option>
                                        <option value="radio">{{ __('Radio Button') }}</option>
                                        <option value="dropdown">{{ __('Dropdown') }}</option>
                                        <option value="checkbox">{{ __('Checkbox') }}</option>
                                    </select>
                                </div>
                                <div class="form-group col-sm-12 col-md-2">
                                    <label class="d-block">{{ __('required') }}</label>
                                    {{-- <div class="custom-control custom-switch">
                                        <input type="hidden" name="required" value="0">
                                        <input type="checkbox" class="custom-control-input required-field" name="required"
                                            id="customSwitch1" value="1">
                                        <label class="custom-control-label" for="customSwitch1">{{ __('Yes') }}</label>
                                    </div> --}}

                                    <div class="custom-switches-stacked mt-2">
                                        <label class="custom-switch" for="customSwitch1">
                                             <input type="hidden" name="required" value="0">
                                            <input type="checkbox" name="required" id="customSwitch1" value="1" class="custom-switch-input required-field ">
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description">{{ __('Active') }}</span>
                                        </label>
                                    </div>
                                </div>

                            </div>
                            <div class="default-values-section" style="display: none" data-parsley-excluded="true">
                                <div class="mt-4" data-repeater-list="default_data">
                                    <div class="col-md-5 pl-0 mb-4">
                                        <button type="button" class="btn btn-success add-new-option" data-repeater-create
                                            title="{{ __('add_new_option') }}">
                                            <span><i class="fa fa-plus"></i> {{ __('add_new_option') }}</span>
                                        </button>
                                    </div>
                                    <div class="row option-section d-flex align-items-center" data-repeater-item>
                                        <div class="form-group col-md-5">
                                            <label>{{ __('option') }} - <span class="option-number"> {{ __('1') }} </span> <span
                                                    class="text-danger"> * </span></label>
                                            <input type="text" name="default_data[0][option]" placeholder="{{ __('text') }}"
                                                class="form-control">
                                        </div>
                                        <div class="form-group col-md-1 pl-0 mt-4 align-items-center">
                                            <button data-repeater-delete type="button"
                                                class="btn btn-icon bg-danger text-white remove-default-option"
                                                title="{{ __('remove') . ' ' . __('option') }}" disabled>
                                                <i class="fa fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit"
                                value="{{ __('submit') }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>
        @endcan
        <!-- Table List -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('list') . ' ' . __('custom-fields') }}
                        </h4>
                        <div class="d-flex justify-content-end">
                            <button type="button" class="btn btn-secondary" id="preview-fields" data-toggle="modal"
                                data-target="#previewFieldModal">{{ __('preview') . ' ' . __('custom-fields') }}</button>
                        </div>
                        <div class="col-12 mt-4 text-right">
                            <b><a href="#" class="table-list-type active mr-2"
                                    data-id="0">{{ __('all') }}</a></b> {{ __('|') }} <a href="#"
                                class="ml-2 table-list-type" data-id="1">{{ __('Trashed') }}</a>
                        </div>
                        <table aria-describedby="mydesc" class="table reorder-table-row" id="table_list"
                            data-table="custom_form_fields" data-toggle="table" data-status-column="is_required"
                            data-url="{{ route('custom-form-fields.show', 0) }}" data-click-to-select="true"
                            data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]"
                            data-search="true" data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                            data-trim-on-search="false" data-mobile-responsive="true" data-use-row-attr-func="true"
                            data-reorderable-rows="true" data-maintain-selected="true" data-export-data-type="all"
                            data-export-options='{ "fileName": "{{ __('custom-fields') }}-<?= date('d-m-y') ?>"
                            ,"ignoreColumn":["operate", "is_required"]}'
                            data-show-export="true" data-query-params="customFieldsQueryParams">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false">
                                        {{ __('id') }}</th>
                                    <th scope="col" data-field="no">{{ __('no.') }}</th>
                                    <th scope="col" data-field="name" data-sortable="true">{{ __('name') }}</th>
                                    <th scope="col" data-field="type" data-sortable="true">{{ __('type') }}</th>
                                    {{-- <th scope="col" data-field="user_type">{{ __('user_type') }}</th> --}}
                                    <th scope="col" data-field="is_required" data-formatter="statusFormatter" data-export="false">
                                        {{ __('is') . ' ' . __('required') }}</th>
                                    <th scope="col" data-field="is_required_export" data-visible="true" data-export="true" class="d-none">{{ __('Is Required (Export)') }}</th>
                                    <th scope="col" data-field="default_values"
                                        data-formatter="formFieldDefaultValuesFormatter">{{ __('Default Values') }}</th>
                                    <th scope="col" data-field="sort_order" data-sortable="false">{{ __('rank') }}
                                    </th>
                                    <th scope="col" data-field="operate" data-sortable="false"
                                        data-events="formFieldsEvents" data-escape="false">{{ __('action') }}</th>
                                </tr>
                            </thead>
                        </table>
                        <span
                            class="d-block mb-4 mt-2 text-danger small">{{ __('Note :- you can change the rank of rows by dragging rows') }}</span>
                        <div class="mt-1 d-md-block">
                            <button id="change-order-form-field" class="btn btn-primary">{{ __('update_rank') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Preview Fields Modal -->
        <div class="modal fade" id="previewFieldModal" tabindex="-1" role="dialog"
            aria-labelledby="exampleModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="exampleModalLabel">{{ __('preview') . ' ' . __('custom-fields') }}
                        </h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true"><i class="fa fa-close"></i></span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="row preview-content">
                            @if (!empty($formFields))
                                @foreach ($formFields as $data)
                                    <div class="form-group col-md-4 col-sm-12">
                                        <label for="{{ $data->name }}">{{ $data->name }}
                                            @if ($data->is_required)
                                                <span class="text-danger">*</span>
                                            @endif
                                        </label>
                                        
                                        @if ($data->type === 'textbox')
                                            <input type="text" name="{{ $data->name }}" id="{{ $data->name }}"
                                                class="form-control" placeholder="{{ $data->name }}"
                                                @if ($data->is_required) required @endif>
                                        @elseif($data->type === 'number')
                                            <input type="number" name="{{ $data->name }}" id="{{ $data->name }}"
                                                min="0" class="form-control" placeholder="{{ $data->name }}"
                                                @if ($data->is_required) required @endif>
                                        @elseif($data->type === 'dropdown')
                                            <select name="{{ $data->name }}" id="{{ $data->name }}"
                                                class="form-control" @if ($data->is_required) required @endif>
                                                <option value="" disabled selected>Select {{ $data->name }}</option>
                                                @if (!empty($data->options))
                                                    @foreach ($data->options as $option)
                                                        <option value="{{ $option->option }}">{{ $option->option }}</option>
                                                    @endforeach
                                                @endif
                                            </select>
                                        @elseif($data->type === 'radio')
                                            <div class="d-flex flex-wrap">
                                                @if (!empty($data->options))
                                                    @foreach ($data->options as $option)
                                                        <div class="form-check form-check-inline mr-3">
                                                            <input type="radio" name="{{ $data->name }}"
                                                                id="{{ $data->name . '_' . $option->id }}"
                                                                value="{{ $option->option }}" class="form-check-input"
                                                                @if ($data->is_required) required @endif>
                                                            <label class="form-check-label"
                                                                for="{{ $data->name . '_' . $option->id }}">
                                                                {{ $option->option }}
                                                            </label>
                                                        </div>
                                                    @endforeach
                                                @endif
                                            </div>
                                        @elseif($data->type === 'checkbox')
                                            <div class="d-flex flex-wrap">
                                                @if (!empty($data->options))
                                                    @foreach ($data->options as $option)
                                                        <div class="form-check mr-3">
                                                            <input type="checkbox" name="{{ $data->name }}[]"
                                                                id="{{ $data->name . '_' . $option->id }}"
                                                                value="{{ $option->option }}" class="form-check-input"
                                                                @if ($data->is_required) required @endif>
                                                            <label class="form-check-label"
                                                                for="{{ $data->name . '_' . $option->id }}">
                                                                {{ $option->option }}
                                                            </label>
                                                        </div>
                                                    @endforeach
                                                @endif
                                            </div>
                                        @elseif($data->type === 'fileupload' || $data->type === 'fileinput')
                                            <div class="input-group col-xs-12">
                                                <input type="file" name="{{ $data->name }}"
                                                    id="{{ $data->name }}" class="file-upload-default"
                                                    @if ($data->is_required) required @endif />
                                                <input type="text" class="form-control file-upload-info" disabled
                                                    placeholder="{{ __('Upload file') }}" />
                                                <span class="input-group-append">
                                                    <button class="file-upload-browse btn btn-primary"
                                                        type="button">{{ __('Upload') }}</button>
                                                </span>
                                            </div>
                                        @elseif($data->type === 'textarea')
                                            <textarea name="{{ $data->name }}" id="{{ $data->name }}" class="form-control"
                                                placeholder="{{ $data->name }}" @if ($data->is_required) required @endif></textarea>
                                        @else
                                            {{-- Unknown type fallback --}}
                                            <input type="text" name="{{ $data->name }}" id="{{ $data->name }}"
                                                class="form-control" placeholder="{{ $data->name }}"
                                                @if ($data->is_required) required @endif>
                                        @endif
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('style')
    <style>
        #table_list th[data-field="is_required_export"],
        #table_list td[data-field="is_required_export"] {
            display: none;
        }
    </style>
@endsection

@section('script')
    <script>
        function formSuccessFunction(response) {
            setTimeout(() => {
                location.reload();
            }, 2000);
        }
        $(document).ready(function() {
            // Input validation
            window.validateInput = function(event) {
                var charCode = event.which || event.keyCode;
                if (!(charCode >= 65 && charCode <= 90) && !(charCode >= 97 && charCode <= 122) && !(
                        charCode === 32)) {
                    event.preventDefault();
                }
            };
            
            // Initialize delete button states on page load
            toggleAccessOfDeleteButtons();

            // after table loads check
            $('#table_list').on('load-success.bs.table', function (e, data) {
                // Hide Action column if no rows have any actions (all operate fields are empty)
                if (data && data.rows) {
                    const hasAnyActions = data.rows.some(row => row.operate && row.operate.trim() !== '');
                    if (!hasAnyActions) {
                        $('#table_list').bootstrapTable('hideColumn', 'operate');
                    } else {
                        $('#table_list').bootstrapTable('showColumn', 'operate');
                    }
                }
            });
            
            // On page load, if type is text/number/file, ensure hidden section inputs are not required
            var selectedType = $('#type-field').val();
            if (selectedType && !['dropdown', 'radio', 'checkbox'].includes(selectedType)) {
                $('.default-values-section').find('input').removeAttr('required').removeAttr('data-parsley-required');
                $('.default-values-section').find('input').removeClass('parsley-error');
                $('.default-values-section').find('.parsley-errors-list').remove();
                // Exclude from Parsley validation
                $('.default-values-section').find('input').attr('data-parsley-excluded', 'true');
            }
            
            // Before form submit, ensure hidden section is excluded from validation
            $('.create-form').on('submit', function(e) {
                var selectedType = $('#type-field').val();
                if (selectedType && !['dropdown', 'radio', 'checkbox'].includes(selectedType)) {
                    // Ensure hidden section inputs are excluded from validation
                    $('.default-values-section').find('input').removeAttr('required').removeAttr('data-parsley-required');
                    $('.default-values-section').find('input').attr('data-parsley-excluded', 'true');
                }
            });
        });
    </script>
@endsection

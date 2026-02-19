
@extends('layouts.app')

@section('title')
    {{ __('Custom Fields') }} / {{ __('Sub Category') }}
@endsection

@section('page-title')
    <h1 class="mb-0"> @yield(\'title\') <a href="#" data-toggle="modal" data-target="#helpModal" title="{{ __('How to use this page') }}">
            <i class="fas fa-question-circle text-primary ml-2"></i>
        </a>
    </h1>
    <div class="section-header-button ml-auto">
        <a href="{{ route('categories.index') }}" class="btn btn-primary rounded mr-2">← {{ __('Back To Category') }}</a>
        <a href="{{ route('custom-fields.create') }}" class="btn btn-primary rounded">+ {{ __('Create Custom Field') }}</a>
    </div> @endsection

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-border" aria-describedby="mydesc" id="table_list"
                               data-toggle="table" data-url=""
                               data-click-to-select="true" data-side-pagination="server" data-pagination="true"
                               data-page-list="[5, 10, 20, 50, 100]" data-search="true" data-search-align="right"
                               data-escape="true" data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                               data-trim-on-search="false" data-responsive="true" data-sort-name="id" data-sort-order="desc"
                               data-pagination-successively-size="3" data-query-params="queryParams" data-mobile-responsive="true"
                               data-show-export="true" data-export-options='{"fileName": "custom-fields-list","ignoreColumn": ["operate"]}'
                               data-export-types="['pdf','json', 'xml', 'csv', 'txt', 'sql', 'doc', 'excel']">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="state" data-checkbox="true"></th>
                                    <th scope="col" data-field="id" data-align="center" data-sortable="true">{{ __('ID') }}</th>
                                    <th scope="col" data-field="image" data-align="center" data-formatter="imageFormatter">{{ __('Image') }}</th>
                                    <th scope="col" data-field="name" data-align="left" data-sortable="true">{{ __('Custom Field') }}</th>
                                    <th scope="col" data-field="operate" data-escape="false" data-sortable="false" data-formatter="actionColumnFormatter" data-align="center">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Help Modal -->
    <div class="modal fade" id="helpModal" tabindex="-1" role="dialog" aria-labelledby="helpModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
            <div class="modal-content rounded shadow">
                <div class="modal-header text-black py-3 border-bottom-0">
                    <h5 class="modal-title text-primary font-weight-bold" id="helpModalLabel">{{ __('How to Use the Custom Fields Page') }}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true" class="text-primary"> {{ __('×') }} </span>
                    </button>
                </div>
                <div class="modal-body px-4 px-md-5 py-1">
                    <p class="lead text-muted mb-4">{{ __('This page allows you to manage custom fields for subcategories. Follow these steps to use the page effectively:') }}</p>
                    <ul class="list-group">
                        <li class="list-group-item list-group-item-action bg-light rounded shadow-sm mb-3 d-flex align-items-center border-0">
                            <strong class="text-primary mr-2">{{ __('Create a Custom Field') }}:</strong> {{ __('Click the "Create Custom Field" button to add a new custom field for the subcategory.') }}
                        </li>
                        <li class="list-group-item list-group-item-action bg-light rounded shadow-sm mb-3 d-flex align-items-center border-0">
                            <strong class="text-primary mr-2">{{ __('Set Order') }}:</strong> {{ __('Use the "Set Order of Custom Fields" link to rearrange the display order of custom fields.') }}
                        </li>
                        <li class="list-group-item list-group-item-action bg-light rounded shadow-sm mb-3 d-flex align-items-center border-0">
                            <strong class="text-primary mr-2">{{ __('View Details') }}:</strong> {{ __('The table lists all custom fields with their ID, Image, Name, and Actions.') }}
                        </li>
                        <li class="list-group-item list-group-item-action bg-light rounded shadow-sm mb-3 d-flex align-items-center border-0">
                            <strong class="text-primary mr-2">{{ __('Search and Filter') }}:</strong> {{ __('Use the search bar to find specific custom fields or adjust the table settings.') }}
                        </li>
                        <li class="list-group-item list-group-item-action bg-light rounded shadow-sm mb-3 d-flex align-items-center border-0">
                            <strong class="text-primary mr-2">{{ __('Export Data') }}:</strong> {{ __('Export the custom fields list in various formats (PDF, CSV, Excel, etc.) using the export options.') }}
                        </li>
                        <li class="list-group-item list-group-item-action bg-light rounded shadow-sm mb-3 d-flex align-items-center border-0">
                            <strong class="text-primary mr-2">{{ __('Edit/Delete') }}:</strong> {{ __('Use the "Action" column to edit or delete a custom field (if you have permissions).') }}
                        </li>
                    </ul>
                </div>
                <div class="modal-footer bg-black text-white py-3 border-top-0">
                    <button type="button" class="btn btn-light rounded px-4" data-dismiss="modal">{{ __('Close') }}</button>
                </div>
            </div>
        </div>
    </div> @endsection

@section('scripts')
    <script>
        $(document).ready(function () {
            $('#helpModal').on('show.bs.modal', function (event) {
                console.log('Help modal opened');
            });
        });
    </script>
@endsection

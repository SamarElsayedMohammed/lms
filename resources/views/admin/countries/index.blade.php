@extends('layouts.app')

@php
$canEdit = auth()->user()->can('countries-edit');
@endphp

@section('title')
{{ __('Countries') }}
@endsection

@section('page-title')
<h1 class="mb-0">@yield('title')</h1>

@can('countries-create')
<div class="section-header-button ml-auto">
    <a class="btn btn-primary" href="javascript:void(0)" data-toggle="modal" data-target="#createCountryModal">
        + {{ __('Add Country') }}
    </a>
</div>
@endcan
@endsection

@section('main')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">
                <div id="toolbar"></div>
                <div class="table-responsive">
                    <table class="table table-border" id="table_list" data-toggle="table"
                        data-url="{{ route('countries.index') }}" data-pagination="true" data-side-pagination="server"
                        data-search="true" data-toolbar="#toolbar" data-page-list="[5, 10, 20, 50, 100]"
                        data-show-columns="true" data-show-refresh="true" data-sort-name="id" data-sort-order="desc"
                        data-mobile-responsive="true" data-table="countries">
                        <thead>
                            <tr>
                                <th data-field="id" data-align="center" data-sortable="true">{{ __('ID') }}</th>
                                <th data-field="name_en" data-sortable="true">{{ __('Country Name (EN)') }}</th>
                                <th data-field="name_ar" data-sortable="true">{{ __('Country Name (AR)') }}</th>
                                <th data-field="status_display" data-align="center" data-sortable="false">{{
                                    __('Active') }}</th>
                                <th data-field="operate" data-align="center" data-escape="false" data-export="false">{{
                                    __('Action') }}</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@can('countries-create')
<!-- Create Modal -->
<div class="modal fade" id="createCountryModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form action="{{ route('countries.store') }}" method="POST" class="create-form">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Add Country') }}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>{{ __('Country Name (EN)') }} <span class="text-danger">*</span></label>
                        <input type="text" name="name_en" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>{{ __('Country Name (AR)') }} <span class="text-danger">*</span></label>
                        <input type="text" name="name_ar" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>{{ __('Status') }}</label>
                        <select name="status" class="form-control">
                            <option value="1" selected>{{ __('Active') }}</option>
                            <option value="0">{{ __('Inactive') }}</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Close') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan

@can('countries-edit')
<!-- Edit Modal -->
<div class="modal fade" id="editCountryModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form action="" method="POST" class="edit-form" id="editCountryForm">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Edit Country') }}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>{{ __('Country Name (EN)') }} <span class="text-danger">*</span></label>
                        <input type="text" name="name_en" id="edit_name_en" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>{{ __('Country Name (AR)') }} <span class="text-danger">*</span></label>
                        <input type="text" name="name_ar" id="edit_name_ar" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Close') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Update') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan
@endsection

@section('script')
<script>
    $(document).ready(function () {
        // Handle Edit Click
        $(document).on('click', '.edit-country-btn', function () {
            var id = $(this).data('id');
            var name_en = $(this).data('name-en');
            var name_ar = $(this).data('name-ar');

            $('#edit_name_en').val(name_en);
            $('#edit_name_ar').val(name_ar);

            var updateUrl = "{{ route('countries.update', ':id') }}";
            updateUrl = updateUrl.replace(':id', id);
            $('#editCountryForm').attr('action', updateUrl);

            $('#editCountryModal').modal('show');
        });
    });
</script>
@endsection
@extends('layouts.app')

@php
    use Illuminate\Support\Str;
@endphp

@section('title')
    {{ __('SEO Settings') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a href="{{ route('admin.seo-settings.create') }}" class="btn btn-primary">
            <i class="fa fa-plus"></i> {{ __('Add SEO Settings') }}
        </a>
    </div>
@endsection

@section('main')
    <div class="content-wrapper">
        <!-- Table List -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="mt-4 text-right">
                                <b><a href="#" class="table-list-type active mr-2" data-id="0">{{ __('All') }}</a></b> {{ __('|') }} <a href="#" class="ml-2 table-list-type" data-id="1">{{ __('Trashed') }}</a>
                            </div>
                        </div>
                        <h4 class="card-title mt-4">{{ __('SEO Settings List') }}</h4>

                        <div id="toolbar"></div>
                        <table aria-describedby="mydesc" class="table" id="table_list" data-toggle="table" 
                            data-url="{{ route('admin.seo-settings.show', 0) }}" 
                            data-side-pagination="server" data-pagination="true" 
                            data-page-list="[5, 10, 20, 50, 100]" data-search="true" 
                            data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true" 
                            data-trim-on-search="false" data-mobile-responsive="true" 
                            data-maintain-selected="true" data-export-data-type="all"
                            data-export-options='{ "fileName": "{{ __('seo-settings') }}-<?= date('d-m-y') ?>", "ignoreColumn": ["operate"] }'
                            data-show-export="true" data-query-params="seoSettingsQueryParams"
                            data-click-to-select="true" data-sort-name="id" data-sort-order="desc">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false">{{ __('id') }}</th>
                                    <th scope="col" data-field="no" data-escape="true">{{ __('no.') }}</th>
                                    <th scope="col" data-field="language" data-sortable="true" data-escape="true">{{ __('Language') }}</th>
                                    <th scope="col" data-field="page_type_display" data-sortable="true" data-formatter="pageTypeFormatter" data-escape="false">{{ __('Page Type') }}</th>
                                    <th scope="col" data-field="meta_title" data-sortable="true" data-formatter="metaTitleFormatter" data-escape="false">{{ __('Meta Title') }}</th>
                                    <th scope="col" data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-escape="false">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script>
    $(document).ready(function() {
        // Handle All/Trashed tab switching
        $('.table-list-type').on('click', function(e){
            e.preventDefault();
            $('.table-list-type').removeClass('active');
            $(this).addClass('active');
            
            // Refresh table
            $('#table_list').bootstrapTable('refresh');
        });
    });

    function pageTypeFormatter(value, row) {
        const pageTypes = @json($pageTypes);
        return pageTypes[row.page_type] || row.page_type || value;
    }

    function metaTitleFormatter(value, row) {
        if (!value) return '-';
        return value.length > 50 ? value.substring(0, 50) + '...' : value;
    }
</script>
@endsection

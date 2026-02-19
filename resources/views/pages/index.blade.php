@extends('layouts.app')
@section('title')
    {{ __('Manage Pages') }}
@endsection
@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto"></div>
@endsection

@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        @can('pages-create')
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('Create Page') }}
                        </h4>

                        {{-- Form start --}}
                        <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('pages.store') }}"
                            data-success-function="formSuccessFunction" data-parsley-validate enctype="multipart/form-data">
                            @csrf
                            <div class="row">
                                {{-- Language --}}
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="language_id" class="form-label">{{ __('Language') }}</label>
                                    <select name="language_id" id="language_id" class="form-control" data-parsley-required="true">
                                        <option value="">{{ __('Select Language') }}</option>
                                        @foreach(\App\Models\Language::where('status', 1)->get() as $language)
                                            <option value="{{ $language->id }}">{{ $language->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                {{-- Page Type --}}
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="page_type" class="form-label">{{ __('Page Type') }}</label>
                                    <select name="page_type" id="page_type" class="form-control" data-parsley-required="true">
                                        <option value="">{{ __('Select Page Type') }}</option>
                                        <option value="about-us">{{ __('About Us') }}</option>
                                        <option value="cookies-policy">{{ __('Cookies Policy') }}</option>
                                        <option value="privacy-policy">{{ __('Privacy Policy') }}</option>
                                        <option value="refund-policy">{{ __('Refund Policy') }}</option>
                                        <option value="terms-and-conditions">{{ __('Terms & Conditions') }}</option>
                                        <option value="custom">{{ __('Custom') }}</option>
                                    </select>
                                </div>

                                {{-- Title --}}
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="title" class="form-label">{{ __('Title') }}</label>
                                    <input type="text" name="title" id="title" placeholder="{{ __('Page Title') }}" class="form-control" data-parsley-required="true" maxlength="500">
                                </div>

                                {{-- Slug --}}
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="slug" class="form-label">{{ __('Slug') }}</label>
                                    <input type="text" name="slug" id="slug" placeholder="{{ __('URL-friendly slug (auto-generated from title)') }}" class="form-control" maxlength="500">
                                    <small class="form-text text-muted">{{ __('Leave empty to auto-generate from title') }}</small>
                                </div>

                                {{-- Page Content --}}
                                <div class="form-group col-sm-12">
                                    <label for="page_content" class="form-label">{{ __('Page Content') }}</label>
                                    <textarea name="page_content" id="tinymce-page-content" class="form-control tinymce-editor" rows="10"></textarea>
                                </div>

                                {{-- Page Icon --}}
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="page_icon" class="form-label">{{ __('Page Icon') }}</label>
                                    <input type="file" name="page_icon" id="page_icon" class="form-control image" accept="image/jpeg,image/jpg,image/png,image/svg+xml,image/webp">
                                    <small class="form-text text-muted">{{ __('Allowed formats: JPG, PNG, SVG, WebP (Max 2MB)') }}</small>
                                    <div class="preview-image-container mt-2" style="display: none;">
                                        <img src="" alt="Preview" class="preview-image img-thumbnail" style="max-width: 150px; max-height: 150px;">
                                    </div>
                                </div>

                                {{-- OG Image --}}
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="og_image" class="form-label">{{ __('OG Image') }}</label>
                                    <input type="file" name="og_image" id="og_image" class="form-control image" accept="image/jpeg,image/jpg,image/png,image/svg+xml,image/webp">
                                    <small class="form-text text-muted">{{ __('Allowed formats: JPG, PNG, SVG, WebP (Max 2MB)') }}</small>
                                    <div class="preview-image-container mt-2" style="display: none;">
                                        <img src="" alt="Preview" class="preview-image img-thumbnail" style="max-width: 150px; max-height: 150px;">
                                    </div>
                                </div>

                                {{-- Meta Title --}}
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="meta_title" class="form-label">{{ __('Meta Title') }}</label>
                                    <textarea name="meta_title" id="meta_title" class="form-control" rows="2" placeholder="{{ __('SEO Meta Title') }}"></textarea>
                                </div>

                                {{-- Meta Description --}}
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="meta_description" class="form-label">{{ __('Meta Description') }}</label>
                                    <textarea name="meta_description" id="meta_description" class="form-control" rows="2" placeholder="{{ __('SEO Meta Description') }}"></textarea>
                                </div>

                                {{-- Meta Keywords --}}
                                <div class="form-group col-sm-12">
                                    <label for="meta_keywords" class="form-label">{{ __('Meta Keywords') }}</label>
                                    <textarea name="meta_keywords" id="meta_keywords" class="form-control" rows="2" placeholder="{{ __('Comma-separated keywords') }}"></textarea>
                                </div>

                                {{-- Schema Markup --}}
                                <div class="form-group col-sm-12">
                                    <label for="schema_markup" class="form-label">{{ __('Schema Markup (JSON-LD)') }}</label>
                                    <textarea name="schema_markup" id="schema_markup" class="form-control" rows="5" placeholder="{{ __('JSON-LD structured data') }}"></textarea>
                                </div>

                                {{-- Hidden fields for auto-managed toggles --}}
                                <input type="hidden" name="is_custom" id="is_custom" value="0">
                                <input type="hidden" name="is_termspolicy" id="is_termspolicy" value="0">
                                <input type="hidden" name="is_privacypolicy" id="is_privacypolicy" value="0">
                                <input type="hidden" name="status" value="1">
                            </div>
                            <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit" value="{{ __('Submit') }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>
        @endcan

        <!-- Table List -->
        <div class="row mt-4">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('List Pages') }}</h4>

                        {{-- Show Trash Button --}}
                        <div class="col-12 mt-4 text-right">
                            <b><a href="#" class="table-list-type active mr-2" data-id="0">{{ __('All') }}</a></b> {{ __('|') }} <a href="#" class="ml-2 table-list-type" data-id="1">{{ __('Trashed') }}</a>
                        </div>
                        <table aria-describedby="mydesc" class="table" id="table_list" data-table="pages" data-toggle="table" data-url="{{ route('pages.show', 0) }}" data-click-to-select="true"
                            data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100]" data-search="true" data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true" data-trim-on-search="false" data-mobile-responsive="true" data-maintain-selected="true"
                            data-export-data-type="all" data-export-options='{ "fileName": "{{ __('pages') }}-<?=
    date('d-m-y')
?>","ignoreColumn":["operate", "status"]}' data-show-export="true" data-query-params="pageQueryParams" data-status-column="status" data-table="pages" data-status-column="status">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false" data-escape="true">{{ __('ID') }}</th>
                                    <th scope="col" data-field="no" data-escape="true">{{ __('No.') }}</th>
                                    <th scope="col" data-field="language_name" data-sortable="false" data-escape="true">{{ __('Language') }}</th>
                                    <th scope="col" data-field="title" data-sortable="true" data-escape="true">{{ __('Title') }}</th>
                                    <th scope="col" data-field="page_type" data-sortable="true" data-escape="true">{{ __('Page Type') }}</th>
                                    <th scope="col" data-field="slug" data-sortable="true" data-escape="true">{{ __('Slug') }}</th>
                                    <th scope="col" data-field="status" data-formatter="statusFormatter" data-export="false" data-escape="false">{{ __('Status') }}</th>
                                    <th scope="col" data-field="status_export" data-visible="true" data-export="true" class="d-none">{{ __('Status (Export)') }}</th>
                                    <th scope="col" data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="pageEvents" data-escape="false">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Page Modal -->
    <div class="modal fade" id="pageEditModal" tabindex="-1" aria-labelledby="pageEditModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form id="edit-form" method="POST" class="edit-form" data-parsley-validate enctype="multipart/form-data">
                @csrf
                @method('PUT')
                <input type="hidden" name="page_id" id="page-id">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="pageEditModalLabel">{{ __('Edit Page') }}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                            <span aria-hidden="true" style="font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff;">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body">
                        <div class="row">
                            {{-- Language --}}
                            <div class="form-group col-md-6">
                                <label for="edit-language_id">{{ __('Language') }}</label>
                                <select name="language_id" id="edit-language_id" class="form-control" required>
                                    <option value="">{{ __('Select Language') }}</option>
                                    @foreach(\App\Models\Language::where('status', 1)->get() as $language)
                                        <option value="{{ $language->id }}">{{ $language->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Page Type --}}
                            <div class="form-group col-md-6">
                                <label for="edit-page_type">{{ __('Page Type') }}</label>
                                <select name="page_type" id="edit-page_type" class="form-control" required>
                                    <option value="">{{ __('Select Page Type') }}</option>
                                    <option value="about-us">{{ __('About Us') }}</option>
                                    <option value="cookies-policy">{{ __('Cookies Policy') }}</option>
                                    <option value="privacy-policy">{{ __('Privacy Policy') }}</option>
                                    <option value="refund-policy">{{ __('Refund Policy') }}</option>
                                    <option value="terms-and-conditions">{{ __('Terms & Conditions') }}</option>
                                    <option value="custom">{{ __('Custom') }}</option>
                                </select>
                            </div>

                            {{-- Title --}}
                            <div class="form-group col-md-6">
                                <label for="edit-title">{{ __('Title') }}</label>
                                <input type="text" class="form-control" id="edit-title" name="title" required maxlength="500">
                            </div>

                            {{-- Slug --}}
                            <div class="form-group col-md-6">
                                <label for="edit-slug">{{ __('Slug') }}</label>
                                <input type="text" class="form-control" id="edit-slug" name="slug" maxlength="500">
                            </div>

                            {{-- Page Content --}}
                            <div class="form-group col-12">
                                <label for="edit-page_content">{{ __('Page Content') }}</label>
                                <textarea class="form-control tinymce-editor" id="edit-page_content" name="page_content" rows="10"></textarea>
                            </div>

                            {{-- Page Icon --}}
                            <div class="form-group col-md-6">
                                <label for="edit-page_icon">{{ __('Page Icon') }}</label>
                                <input type="file" class="form-control image" id="edit-page_icon" name="page_icon" accept="image/jpeg,image/jpg,image/png,image/svg+xml,image/webp">
                                <small class="form-text text-muted">{{ __('Allowed formats: JPG, PNG, SVG, WebP (Max 2MB)') }}</small>
                                <div id="edit-page_icon-preview" class="mt-2" style="display: none;">
                                    <img src="" alt="Preview" class="img-thumbnail" style="max-width: 150px; max-height: 150px;">
                                </div>
                                <div id="edit-page_icon-current" class="mt-2"></div>
                            </div>

                            {{-- OG Image --}}
                            <div class="form-group col-md-6">
                                <label for="edit-og_image">{{ __('OG Image') }}</label>
                                <input type="file" class="form-control image" id="edit-og_image" name="og_image" accept="image/jpeg,image/jpg,image/png,image/svg+xml,image/webp">
                                <small class="form-text text-muted">{{ __('Allowed formats: JPG, PNG, SVG, WebP (Max 2MB)') }}</small>
                                <div id="edit-og_image-preview" class="mt-2" style="display: none;">
                                    <img src="" alt="Preview" class="img-thumbnail" style="max-width: 150px; max-height: 150px;">
                                </div>
                                <div id="edit-og_image-current" class="mt-2"></div>
                            </div>

                            {{-- Meta Title --}}
                            <div class="form-group col-md-6">
                                <label for="edit-meta_title">{{ __('Meta Title') }}</label>
                                <textarea class="form-control" id="edit-meta_title" name="meta_title" rows="2"></textarea>
                            </div>

                            {{-- Meta Description --}}
                            <div class="form-group col-md-6">
                                <label for="edit-meta_description">{{ __('Meta Description') }}</label>
                                <textarea class="form-control" id="edit-meta_description" name="meta_description" rows="2"></textarea>
                            </div>

                            {{-- Meta Keywords --}}
                            <div class="form-group col-12">
                                <label for="edit-meta_keywords">{{ __('Meta Keywords') }}</label>
                                <textarea class="form-control" id="edit-meta_keywords" name="meta_keywords" rows="2"></textarea>
                            </div>

                            {{-- Schema Markup --}}
                            <div class="form-group col-12">
                                <label for="edit-schema_markup">{{ __('Schema Markup (JSON-LD)') }}</label>
                                <textarea class="form-control" id="edit-schema_markup" name="schema_markup" rows="5"></textarea>
                            </div>

                            {{-- Hidden fields for auto-managed toggles --}}
                            <input type="hidden" name="is_custom" id="edit-is_custom" value="0">
                            <input type="hidden" name="is_termspolicy" id="edit-is_termspolicy" value="0">
                            <input type="hidden" name="is_privacypolicy" id="edit-is_privacypolicy" value="0">
                            <input type="hidden" name="status" id="edit-status" value="1">
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Update') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('style')
    <style>
        #table_list th[data-field="status_export"],
        #table_list td[data-field="status_export"] {
            display: none;
        }
    </style>
@endsection

@section('script')
    <script>
        function formSuccessFunction(response) {
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        }

        // Auto-generate slug from title
        $('#title').on('blur', function() {
            if (!$('#slug').val()) {
                let slug = $(this).val().toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/(^-|-$)/g, '');
                $('#slug').val(slug);
            }
        });

        // Image preview for create form
        $('#page_icon, #og_image').on('change', function() {
            const file = this.files[0];
            const previewContainer = $(this).siblings('.preview-image-container');
            const previewImg = previewContainer.find('.preview-image');

            if (file) {
                const allowedExtensions = /(\.jpg|\.jpeg|\.png|\.svg|\.webp)$/i;
                if (!allowedExtensions.exec(file.name)) {
                    alert('Invalid file type. Please choose JPG, PNG, SVG, or WebP image.');
                    $(this).val('');
                    return;
                }

                const maxFileSize = 2 * 1024 * 1024; // 2MB
                if (file.size > maxFileSize) {
                    alert('File size exceeds 2MB limit.');
                    $(this).val('');
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.attr('src', e.target.result);
                    previewContainer.show();
                };
                reader.readAsDataURL(file);
            } else {
                previewContainer.hide();
            }
        });

        // Image preview for edit form
        $('#edit-page_icon, #edit-og_image').on('change', function() {
            const file = this.files[0];
            const fieldId = $(this).attr('id');
            const previewDiv = $('#' + fieldId + '-preview');
            const previewImg = previewDiv.find('img');

            if (file) {
                const allowedExtensions = /(\.jpg|\.jpeg|\.png|\.svg|\.webp)$/i;
                if (!allowedExtensions.exec(file.name)) {
                    alert('Invalid file type. Please choose JPG, PNG, SVG, or WebP image.');
                    $(this).val('');
                    return;
                }

                const maxFileSize = 2 * 1024 * 1024; // 2MB
                if (file.size > maxFileSize) {
                    alert('File size exceeds 2MB limit.');
                    $(this).val('');
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.attr('src', e.target.result);
                    previewDiv.show();
                    $('#' + fieldId + '-current').hide();
                };
                reader.readAsDataURL(file);
            } else {
                previewDiv.hide();
            }
        });

        // Manage hidden fields based on page type selection
        // Create form
        $('#page_type').on('change', function() {
            const pageType = $(this).val();

            if (pageType === 'terms-and-conditions') {
                $('#is_termspolicy').val('1');
                $('#is_privacypolicy').val('0');
                $('#is_custom').val('0');
            } else if (pageType === 'privacy-policy') {
                $('#is_privacypolicy').val('1');
                $('#is_termspolicy').val('0');
                $('#is_custom').val('0');
            } else if (pageType === 'custom') {
                $('#is_custom').val('1');
                $('#is_termspolicy').val('0');
                $('#is_privacypolicy').val('0');
            } else if (pageType === 'about-us' || pageType === 'cookies-policy') {
                $('#is_custom').val('0');
                $('#is_termspolicy').val('0');
                $('#is_privacypolicy').val('0');
            }
        });

        // Edit form
        $('#edit-page_type').on('change', function() {
            const pageType = $(this).val();

            if (pageType === 'terms-and-conditions') {
                $('#edit-is_termspolicy').val('1');
                $('#edit-is_privacypolicy').val('0');
                $('#edit-is_custom').val('0');
            } else if (pageType === 'privacy-policy') {
                $('#edit-is_privacypolicy').val('1');
                $('#edit-is_termspolicy').val('0');
                $('#edit-is_custom').val('0');
            } else if (pageType === 'custom') {
                $('#edit-is_custom').val('1');
                $('#edit-is_termspolicy').val('0');
                $('#edit-is_privacypolicy').val('0');
            } else if (pageType === 'about-us' || pageType === 'cookies-policy') {
                $('#edit-is_custom').val('0');
                $('#edit-is_termspolicy').val('0');
                $('#edit-is_privacypolicy').val('0');
            }
        });

        // Query params for table
        function pageQueryParams(params) {
            return {
                limit: params.limit,
                offset: params.offset,
                sort: params.sort,
                order: params.order,
                search: params.search,
                show_deleted: $('.table-list-type.active').data('id') == 1 ? 1 : 0
            };
        }

        // Hide status column when viewing trashed items
        $(document).ready(function() {
            // Initial check on page load
            setTimeout(function() {
                toggleStatusColumn();
            }, 300);

            // Listen for table refresh/load events
            $('#table_list').on('load-success.bs.table', function() {
                toggleStatusColumn();
            });

            // Override the global table-list-type handler to also toggle status column
            $(document).off('click', '.table-list-type').on('click', '.table-list-type', function(e) {
                e.preventDefault();
                $('.table-list-type').removeClass('active');
                $(this).addClass('active');

                // Toggle status column before refreshing
                toggleStatusColumn();

                // Refresh the table
                $('#table_list').bootstrapTable('refresh');
            });
        });

        function toggleStatusColumn() {
            const isTrashed = $('.table-list-type.active').data('id') == 1;
            const $table = $('#table_list');

            if (isTrashed) {
                // Hide status column when viewing trashed items
                $table.bootstrapTable('hideColumn', 'status');
            } else {
                // Show status column when viewing all items
                $table.bootstrapTable('showColumn', 'status');
            }
        }

        // Hide Action column if no rows have any actions (all operate fields are empty)
        $('#table_list').on('load-success.bs.table', function (e, data) {
            if (data && data.rows) {
                const hasAnyActions = data.rows.some(row => row.operate && row.operate.trim() !== '');
                if (!hasAnyActions) {
                    $('#table_list').bootstrapTable('hideColumn', 'operate');
                } else {
                    $('#table_list').bootstrapTable('showColumn', 'operate');
                }
            }
        });

        // Custom status formatter for pages
        window.statusFormatter = function(value, row, index) {
            // For normal items, show the toggle
            let checked = (value == 1 || value == true || value == '1') ? 'checked' : '';
            return `
                <div class="custom-control custom-switch custom-switch-2">
                    <input type="checkbox" class="custom-control-input update-status" id="${row.id}" ${checked}>
                    <label class="custom-control-label" for="${row.id}">&nbsp;</label>
                </div>
            `;
        };

        // Page events for edit button
        window.pageEvents = {
            'click .edit_btn': function(e, value, row, index) {
                e.preventDefault();

                // Set form action URL
                $('#edit-form').attr('action', value);

                // Populate form fields
                $('#page-id').val(row.id);
                $('#edit-language_id').val(row.language_id);
                $('#edit-title').val(row.title);
                $('#edit-slug').val(row.slug);
                $('#edit-meta_title').val(row.meta_title || '');
                $('#edit-meta_description').val(row.meta_description || '');
                $('#edit-meta_keywords').val(row.meta_keywords || '');
                $('#edit-schema_markup').val(row.schema_markup || '');
                $('#edit-status').val(row.status == 1 ? 1 : 0);

                // Display existing images
                if (row.page_icon) {
                    const iconUrl = row.page_icon.startsWith('http') ? row.page_icon : '{{ asset("storage") }}/' + row.page_icon;
                    $('#edit-page_icon-current').html('<img src="' + iconUrl + '" alt="Current Icon" class="img-thumbnail" style="max-width: 150px; max-height: 150px;"><br><small>Current Icon</small>');
                } else {
                    $('#edit-page_icon-current').html('');
                }

                if (row.og_image) {
                    const ogUrl = row.og_image.startsWith('http') ? row.og_image : '{{ asset("storage") }}/' + row.og_image;
                    $('#edit-og_image-current').html('<img src="' + ogUrl + '" alt="Current OG Image" class="img-thumbnail" style="max-width: 150px; max-height: 150px;"><br><small>Current OG Image</small>');
                } else {
                    $('#edit-og_image-current').html('');
                }

                // Hide preview divs
                $('#edit-page_icon-preview').hide();
                $('#edit-og_image-preview').hide();

                // Set page type and trigger change to automatically set toggles
                $('#edit-page_type').val(row.page_type).trigger('change');

                // Set TinyMCE content
                if (typeof tinymce !== 'undefined' && tinymce.get('edit-page_content')) {
                    tinymce.get('edit-page_content').setContent(row.page_content || '');
                } else {
                    $('#edit-page_content').val(row.page_content || '');
                }

                // Open modal
                $('#pageEditModal').modal('show');
            }
        };
    </script>
@endsection

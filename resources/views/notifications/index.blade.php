@extends('layouts.app')

@section('title')
    {{ __('Manage Notifications') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
    </div> @endsection

@section('main')
    <div class="content-wrapper">

        <!-- Create Notification Form -->
        @can('notifications-create')
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('Create Notification') }}
                        </h4>
                        <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('notifications.store') }}" enctype="multipart/form-data" data-parsley-validate> @csrf <div class="row">
                                <div class="form-group col-sm-12 col-md-4">
                                    <label>{{ __('Title') }} <span class="text-danger"> * </span></label>
                                    <input type="text" name="title" placeholder="{{ __('Notification title') }}" class="form-control mb-3" required>

                                    <label>{{ __('Type') }} <span class="text-danger"> * </span></label>
                                    <select name="type" id="type" class="form-control mb-3" required>
                                        <option value="default">{{ __('Default') }}</option>
                                        <option value="course">{{ __('Course') }}</option>
                                        <option value="instructor">{{ __('Instructor') }}</option>
                                        <option value="url">{{ __('URL') }}</option>
                                    </select>

                                    {{-- TYPE ID (hidden by default) --}}
                                    <div id="type_id_wrapper" class="d-none">
                                        <label>{{ __('Type ID') }}</label>
                                        <select name="type_id" id="type_id" class="form-control mb-3">
                                            <option value="">{{ __('Select') }}</option>
                                        </select>
                                    </div>

                                    {{-- TYPE LINK (hidden by default) --}}
                                    <div id="type_link_wrapper" class="d-none">
                                        <label>{{ __('Type Link') }}</label>
                                        <input type="text" name="type_link" id="type_link" placeholder="{{ __('Type Link (URL)') }}" class="form-control mb-3">
                                    </div>

                                    <label>{{ __('Image') }}</label>
                                    <input type="file" name="image" class="form-control-file" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp,image/svg+xml">
                                </div>
                                <div class="form-group col-sm-12 col-md-8">
                                    <label>{{ __('Message') }} <span class="text-danger"> * </span> <small class="text-muted">(Max 250 characters)</small></label>
                                    <textarea name="message" id="message" placeholder="{{ __('Notification message') }}" class="form-control" style="height:140px" required rows="4" maxlength="250"></textarea>
                                    <small class="text-muted"><span id="message-char-count">0</span>/250 characters</small>
                                </div>
                            </div>
                            <input class="btn btn-primary float-right ml-3" type="submit" value="{{ __('Submit') }}">
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
                            {{ __('List Notifications') }}
                        </h4>
                        <table aria-describedby="mydesc" class="table reorder-table-row" id="table_list"
                            data-table="notifications" data-toggle="table" data-status-column="is_active"
                            data-url="{{ route('notifications.show', 0) }}" data-click-to-select="true"
                            data-side-pagination="server" data-pagination="true"
                            data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#toolbar"
                            data-show-columns="true" data-show-refresh="true" data-trim-on-search="false"
                            data-mobile-responsive="true" data-use-row-attr-func="true"
                            data-maintain-selected="true" data-export-data-type="all"
                            data-export-options='{ "fileName": "{{ __('notifications') }}-<?= date('d-m-y') ?>","ignoreColumn":["operate"]}'
                            data-show-export="true" data-query-params="queryParams">
                            <thead>
                                <tr>
                                    <th data-field="id" data-visible="false" data-escape="true">{{ __('id') }}</th>
                                    <th data-field="no" data-width="60" data-escape="true">{{ __('no.') }}</th>
                                    <th data-field="title" data-width="200" data-width-unit="px" data-formatter="titleFormatter" data-escape="false" style="width: 200px !important; max-width: 200px !important;">{{ __('Title') }}</th>
                                    <th data-field="message" data-width="300" data-width-unit="px" data-formatter="messageFormatter" data-escape="false" style="width: 300px !important; max-width: 300px !important;">{{ __('Message') }}</th>
                                    <th data-field="type" data-width="100" data-formatter="typeFormatter" data-escape="false">{{ __('Type') }}</th>
                                    <th data-field="type_id_display" data-width="200" data-formatter="typeIdFormatter" data-escape="false">{{ __('Type ID') }}</th>
                                    <th data-field="image" data-width="100" data-align="center" data-formatter="imageFormatter" data-escape="false">{{ __('Image') }}</th>
                                    <th data-field="operate" data-width="100" data-sortable="false" data-formatter="actionColumnFormatter" data-events="taxAction" data-escape="false">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div> @endsection

@push('styles')
@endpush

@push('scripts')
<script>
$(document).ready(function () {
    let courses = @json($courses ?? []);
    let instructors = @json($instructors ?? []);

    console.log('Courses data:', courses);
    console.log('Instructors data:', instructors);

    function toggleFields() {
        let type = $('#type').val();

        // Hide all by default
        $('#type_id_wrapper').addClass('d-none');
        $('#type_link_wrapper').addClass('d-none');

        if (type === 'course') {
            $('#type_id').empty().append('<option value="">{{ __("Select Course") }}</option>');
            $.each(courses, function (i, course) {
                $('#type_id').append('<option value="'+course.id+'">'+course.title+'</option>');
            });
            $('#type_id_wrapper').removeClass('d-none');
        }
        else if (type === 'instructor') {
            $('#type_id').empty().append('<option value="">{{ __("Select Instructor") }}</option>');
            $.each(instructors, function (i, instructor) {
                $('#type_id').append('<option value="'+instructor.id+'">'+instructor.name+'</option>');
            });
            $('#type_id_wrapper').removeClass('d-none');
        }
        else if (type === 'url') {
            $('#type_link_wrapper').removeClass('d-none');
        }
    }

    // Run on change
    $('#type').on('change', toggleFields);

    // Run on page load (in case editing existing)
    toggleFields();

    // Character counter for message field
    $('#message').on('input', function() {
        const length = $(this).val().length;
        $('#message-char-count').text(length);
        if (length > 250) {
            $('#message-char-count').addClass('text-danger');
        } else {
            $('#message-char-count').removeClass('text-danger');
        }
    });

    // Title formatter with view more functionality
    window.titleFormatter = function(value, row, index) {
        if (!value) return '-';

        const maxLength = 50;
        const titleId = 'title-' + row.id;

        if (value.length <= maxLength) {
            return '<span>' + escapeHtml(value) + '</span>';
        }

        const shortText = escapeHtml(value.substring(0, maxLength));
        const fullText = escapeHtml(value);

        return `
            <div id="${titleId}">
                <span class="title-short">${shortText}...</span>
                <span class="title-full d-none">${fullText}</span>
                <a href="javascript:void(0)" class="view-more-link text-primary" onclick="toggleTitle('${titleId}')" style="cursor: pointer; text-decoration: underline;">
                    <span class="view-more-text">View More</span>
                    <span class="view-less-text d-none">View Less</span>
                </a>
            </div>
        `;
    };

    // Toggle title expand/collapse
    window.toggleTitle = function(titleId) {
        const container = document.getElementById(titleId);
        if (!container) return;

        const shortSpan = container.querySelector('.title-short');
        const fullSpan = container.querySelector('.title-full');
        const viewMoreText = container.querySelector('.view-more-text');
        const viewLessText = container.querySelector('.view-less-text');

        if (shortSpan && fullSpan && viewMoreText && viewLessText) {
            if (shortSpan.classList.contains('d-none')) {
                // Collapse
                shortSpan.classList.remove('d-none');
                fullSpan.classList.add('d-none');
                viewMoreText.classList.remove('d-none');
                viewLessText.classList.add('d-none');
            } else {
                // Expand
                shortSpan.classList.add('d-none');
                fullSpan.classList.remove('d-none');
                viewMoreText.classList.add('d-none');
                viewLessText.classList.remove('d-none');
            }
        }
    };

    // Message formatter with view more functionality
    window.messageFormatter = function(value, row, index) {
        if (!value) return '-';

        const maxLength = 100;
        const messageId = 'msg-' + row.id;

        if (value.length <= maxLength) {
            return '<span>' + escapeHtml(value) + '</span>';
        }

        const shortText = escapeHtml(value.substring(0, maxLength));
        const fullText = escapeHtml(value);

        return `
            <div id="${messageId}">
                <span class="message-short">${shortText}...</span>
                <span class="message-full d-none">${fullText}</span>
                <a href="javascript:void(0)" class="view-more-link text-primary" onclick="toggleMessage('${messageId}')" style="cursor: pointer; text-decoration: underline;">
                    <span class="view-more-text">View More</span>
                    <span class="view-less-text d-none">View Less</span>
                </a>
            </div>
        `;
    };

    // Toggle message expand/collapse
    window.toggleMessage = function(messageId) {
        const container = document.getElementById(messageId);
        if (!container) return;

        const shortSpan = container.querySelector('.message-short');
        const fullSpan = container.querySelector('.message-full');
        const viewMoreText = container.querySelector('.view-more-text');
        const viewLessText = container.querySelector('.view-less-text');

        if (shortSpan && fullSpan && viewMoreText && viewLessText) {
            if (shortSpan.classList.contains('d-none')) {
                // Collapse
                shortSpan.classList.remove('d-none');
                fullSpan.classList.add('d-none');
                viewMoreText.classList.remove('d-none');
                viewLessText.classList.add('d-none');
            } else {
                // Expand
                shortSpan.classList.add('d-none');
                fullSpan.classList.remove('d-none');
                viewMoreText.classList.add('d-none');
                viewLessText.classList.remove('d-none');
            }
        }
    };

    // Helper function to escape HTML
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Type formatter - Capitalize first letter
    window.typeFormatter = function(value, row, index) {
        if (!value) return '-';
        const capitalized = value.charAt(0).toUpperCase() + value.slice(1);
        return '<span class="badge badge-info">' + escapeHtml(capitalized) + '</span>';
    };

    // Type ID formatter - Display formatted type ID
    window.typeIdFormatter = function(value, row, index) {
        if (!value || value === '-') return '-';

        // If it's a URL, make it clickable
        if (row.type === 'url' && value.startsWith('http')) {
            return '<a href="' + escapeHtml(value) + '" target="_blank" class="text-primary" style="text-decoration: underline;">' + escapeHtml(value) + '</a>';
        }

        return '<span>' + escapeHtml(value) + '</span>';
    };

    // Set column widths after table is initialized
    function setColumnWidths() {
        // Force column widths using multiple selectors
        setTimeout(function() {
            // Title column (index 2: id=0, no=1, title=2)
            $('#table_list thead th').eq(2).css({
                'width': '200px',
                'max-width': '200px',
                'min-width': '200px'
            }).attr('style', 'width: 200px !important; max-width: 200px !important; min-width: 200px !important;');

            $('#table_list tbody tr').each(function() {
                $(this).find('td').eq(2).css({
                    'width': '200px',
                    'max-width': '200px',
                    'min-width': '200px'
                });
            });

            // Message column (index 3)
            $('#table_list thead th').eq(3).css({
                'width': '300px',
                'max-width': '300px',
                'min-width': '300px'
            }).attr('style', 'width: 300px !important; max-width: 300px !important; min-width: 300px !important;');

            $('#table_list tbody tr').each(function() {
                $(this).find('td').eq(3).css({
                    'width': '300px',
                    'max-width': '300px',
                    'min-width': '300px'
                });
            });

            // Also try by data-field attribute
            $('#table_list th[data-field="title"]').css({
                'width': '200px',
                'max-width': '200px',
                'min-width': '200px'
            });
            $('#table_list td[data-field="title"]').css({
                'width': '200px',
                'max-width': '200px',
                'min-width': '200px'
            });

            $('#table_list th[data-field="message"]').css({
                'width': '300px',
                'max-width': '300px',
                'min-width': '300px'
            });
            $('#table_list td[data-field="message"]').css({
                'width': '300px',
                'max-width': '300px',
                'min-width': '300px'
            });
        }, 100);
    }

    // Set widths on table load
    $('#table_list').on('load-success.bs.table', setColumnWidths);
    $('#table_list').on('refresh.bs.table', setColumnWidths);
    $('#table_list').on('post-body.bs.table', setColumnWidths);

    // Also set on document ready if table is already loaded
    setTimeout(setColumnWidths, 500);

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
});
</script>
@endpush

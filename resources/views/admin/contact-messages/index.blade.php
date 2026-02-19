@extends('layouts.app')

@section('title')
    {{ __('Contact Messages') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
@endsection

@section('main')
    <div class="content-wrapper">
        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-primary">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Messages') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ \App\Models\ContactMessage::count() }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-warning">
                        <i class="fas fa-envelope-open"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('New') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ \App\Models\ContactMessage::new()->count() }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-info">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Read') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ \App\Models\ContactMessage::read()->count() }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Replied') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ \App\Models\ContactMessage::replied()->count() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Table List -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('Contact Messages') }}</h4>

                        <!-- Filter and Search -->
                        <form id="contactMessageSearchForm" method="GET"
                            action="{{ route('admin.contact-messages.index') }}" class="mb-4">
                            <div class="row align-items-end g-3">
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label text-muted small mb-1">{{ __('Status') }}</label>
                                    <select name="status" id="statusFilter" class="form-control">
                                        <option value="">{{ __('All Statuses') }}</option>
                                        <option value="new" {{ request('status') == 'new' ? 'selected' : '' }}>{{ __('New') }}
                                        </option>
                                        <option value="read" {{ request('status') == 'read' ? 'selected' : '' }}>
                                            {{ __('Read') }}</option>
                                        <option value="replied" {{ request('status') == 'replied' ? 'selected' : '' }}>
                                            {{ __('Replied') }}</option>
                                        <option value="closed" {{ request('status') == 'closed' ? 'selected' : '' }}>
                                            {{ __('Closed') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label text-muted small mb-1">{{ __('Date From') }}</label>
                                    <input type="date" name="date_from" id="dateFrom" class="form-control"
                                        value="{{ request('date_from') }}">
                                </div>
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label text-muted small mb-1">{{ __('Date To') }}</label>
                                    <input type="date" name="date_to" id="dateTo" class="form-control"
                                        value="{{ request('date_to') }}">
                                </div>
                                <div class="col-md-1 col-sm-3 d-flex align-items-end">
                                    <button type="button" class="btn btn-primary w-100" id="filterBtn"
                                        title="{{ __('Filter by date range') }}">
                                        <i class="fas fa-filter"></i>
                                    </button>
                                </div>
                                <div class="col-md-1 col-sm-3 d-flex align-items-end">
                                    <button type="button" class="btn btn-secondary w-100" id="resetBtn"
                                        title="{{ __('Reset all filters') }}">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                                <div class="col-md-4 col-sm-12">
                                    <label class="form-label text-muted small mb-1">{{ __('Search') }}</label>
                                    <input type="text" name="search" id="searchInput" class="form-control"
                                        placeholder="{{ __('Search by name or email...') }}"
                                        value="{{ request('search') }}">
                                </div>
                            </div>
                        </form>

                        <!-- Contact Messages Table -->
                        <div class="table-responsive">
                            <table class="table table-striped" id="contactMessagesTable">
                                <thead>
                                    <tr>
                                        <th>{{ __('ID') }}</th>
                                        <th>{{ __('From') }}</th>
                                        <th>{{ __('Message Preview') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th class="sortable" id="dateSort" style="cursor: pointer;">
                                            {{ __('Date') }}
                                            <i class="fas fa-sort sort-icon"></i>
                                        </th>
                                        <th>{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="contactMessagesTableBody">
                                    <!-- Data will be loaded via AJAX -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Message Modal -->
    <div class="modal fade" id="viewMessageModal" tabindex="-1" role="dialog" aria-labelledby="viewMessageModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewMessageModalLabel">{{ __('Contact Message Details') }}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="messageDetailsContainer">
                    <!-- Message details will be loaded here -->
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x"></i>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Close') }}</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1" role="dialog" aria-labelledby="updateStatusModalLabel"
        aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updateStatusModalLabel">{{ __('Update Message Status') }}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form id="updateStatusForm" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="statusSelect">{{ __('Status') }} <span class="text-danger">*</span></label>
                            <select name="status" id="statusSelect" class="form-control" required>
                                <option value="new">{{ __('New') }}</option>
                                <option value="read">{{ __('Read') }}</option>
                                <option value="replied">{{ __('Replied') }}</option>
                                <option value="closed">{{ __('Closed') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Update Status') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('style')
    <style>
        #statusFilter,
        #dateFrom,
        #dateTo,
        #searchInput {
            height: 38px;
        }

        @media (max-width: 767.98px) {
            #contactMessageSearchForm .d-flex {
                flex-direction: column;
            }

            #contactMessageSearchForm .d-flex .btn {
                width: 100%;
            }
        }

        /* Contact Message Details Modal Styling - Simple & Minimal */
        #viewMessageModal .contact-message-details {
            padding: 0;
        }

        #viewMessageModal .detail-card {
            background: #ffffff !important;
            border: 1px solid #dee2e6 !important;
            border-radius: 4px !important;
            margin-bottom: 16px !important;
        }

        #viewMessageModal .detail-card:last-child {
            margin-bottom: 0 !important;
        }

        #viewMessageModal .detail-card-header {
            background: #f8f9fa !important;
            color: #495057 !important;
            padding: 10px 16px !important;
            font-weight: 600 !important;
            font-size: 13px !important;
            border-bottom: 1px solid #dee2e6 !important;
        }

        #viewMessageModal .detail-card-header i {
            font-size: 13px;
            margin-right: 6px;
            color: #6c757d;
        }

        #viewMessageModal .detail-card-body {
            padding: 16px !important;
        }

        #viewMessageModal .info-row {
            display: flex !important;
            flex-direction: row !important;
            align-items: flex-start !important;
            gap: 12px;
            padding: 8px 0 !important;
            border-bottom: 1px solid #f0f0f0 !important;
        }

        #viewMessageModal .info-row:last-child {
            border-bottom: none !important;
            padding-bottom: 0 !important;
        }

        #viewMessageModal .info-row:first-child {
            padding-top: 0 !important;
        }

        #viewMessageModal .info-icon {
            width: 24px !important;
            flex-shrink: 0 !important;
        }

        #viewMessageModal .info-icon i {
            font-size: 16px !important;
            color: #6c757d !important;
        }

        #viewMessageModal .info-content {
            flex: 1 !important;
        }

        #viewMessageModal .info-label {
            font-size: 11px !important;
            text-transform: uppercase !important;
            color: #868e96 !important;
            font-weight: 600 !important;
            letter-spacing: 0.3px;
            margin-bottom: 3px !important;
        }

        #viewMessageModal .info-value {
            color: #212529 !important;
            font-size: 14px !important;
            font-weight: 400 !important;
        }

        #viewMessageModal .info-value a {
            color: #007bff !important;
            text-decoration: none !important;
        }

        #viewMessageModal .info-value a:hover {
            text-decoration: underline !important;
        }

        #viewMessageModal .message-content {
            background: #f8f9fa !important;
            padding: 14px !important;
            border-radius: 4px !important;
            border-left: 3px solid #6c757d !important;
            color: #212529 !important;
            font-size: 14px !important;
            line-height: 1.6 !important;
            white-space: pre-wrap !important;
            word-wrap: break-word !important;
        }

        #viewMessageModal .modal-body {
            padding: 20px !important;
        }

        .sortable {
            user-select: none;
        }

        .sortable:hover {
            background-color: #f0f0f0;
        }

        .sort-icon {
            font-size: 12px;
            margin-left: 5px;
            color: #6c757d;
        }

        .sort-icon.active {
            color: #007bff;
        }
    </style>
@endpush

@section('script')
    <script>
        $(document).ready(function () {
            // Declare variables
            const form = $('#contactMessageSearchForm');
            const tableBody = $('#contactMessagesTableBody');
            const filterBtn = $('#filterBtn');
            const resetBtn = $('#resetBtn');
            const statusFilter = $('#statusFilter');
            const searchInput = $('#searchInput');
            const dateFrom = $('#dateFrom');
            const dateTo = $('#dateTo');

            // Track current sort order
            let currentSortOrder = 'desc'; // Default sorting

            // Debounce function
            function debounce(func, wait) {
                let timeout;
                return function executedFunction(...args) {
                    const later = () => {
                        clearTimeout(timeout);
                        func(...args);
                    };
                    clearTimeout(timeout);
                    timeout = setTimeout(later, wait);
                };
            }

            // Reload statistics cards
            const reloadStatistics = function () {
                $.ajax({
                    url: window.location.href,
                    method: 'GET',
                    success: function (response) {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(response, 'text/html');

                        // Update each statistic card
                        $('.card-statistic-1').each(function (index) {
                            const newCard = $(doc).find('.card-statistic-1').eq(index);
                            if (newCard.length) {
                                $(this).find('.card-body').html(newCard.find('.card-body').html());
                            }
                        });
                    },
                    error: function () {
                        console.log('Failed to reload statistics');
                    }
                });
            };

            // Load contact messages data
            const loadContactMessagesData = function () {
                const formData = form.serialize() + '&sort_field=created_at&sort_order=' + currentSortOrder;
                const url = '{{ route("admin.contact-messages.data") }}?' + formData;

                tableBody.html('<tr><td colspan="6" class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> {{ __("Loading...") }}</td></tr>');

                $.ajax({
                    url: url,
                    method: 'GET',
                    dataType: 'json',
                    success: function (response) {
                        if ((response.error === false || response.status === 'success') && response.data) {
                            let html = '';
                            if (response.data.length > 0) {
                                response.data.forEach(function (row) {
                                    let statusBadge = '';
                                    switch (row.status.toLowerCase()) {
                                        case 'new':
                                            statusBadge = '<span class="badge badge-warning">' + row.status + '</span>';
                                            break;
                                        case 'read':
                                            statusBadge = '<span class="badge badge-info">' + row.status + '</span>';
                                            break;
                                        case 'replied':
                                            statusBadge = '<span class="badge badge-success">' + row.status + '</span>';
                                            break;
                                        case 'closed':
                                            statusBadge = '<span class="badge badge-secondary">' + row.status + '</span>';
                                            break;
                                        default:
                                            statusBadge = '<span class="badge badge-secondary">' + row.status + '</span>';
                                    }

                                    html += '<tr>';
                                    html += '<td>#' + row.id + '</td>';
                                    html += '<td><div><strong>' + row.first_name + '</strong><br><small class="text-muted">' + row.email + '</small></div></td>';
                                    html += '<td>' + row.message_preview + '</td>';
                                    html += '<td>' + statusBadge + '</td>';
                                    html += '<td>' + row.created_at + '</td>';
                                    html += '<td>' + row.operate + '</td>';
                                    html += '</tr>';
                                });
                            } else {
                                html = '<tr><td colspan="6" class="text-center py-4"><div class="empty-state"><i class="fas fa-envelope fa-3x text-muted mb-3"></i><h5>{{ __("No contact messages found") }}</h5><p class="text-muted">{{ __("There are no contact messages matching your criteria.") }}</p></div></td></tr>';
                            }
                            tableBody.html(html);
                        } else {
                            tableBody.html('<tr><td colspan="6" class="text-center py-4 text-danger">{{ __("Error loading data. Please try again.") }}</td></tr>');
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('Failed to load contact messages:', { xhr: xhr, status: status, error: error });
                        let errorMsg = '{{ __("An error occurred while loading data. Please try again.") }}';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        tableBody.html('<tr><td colspan="6" class="text-center py-4 text-danger">' + errorMsg + '</td></tr>');
                    }
                });
            };

            // Load data on page load
            loadContactMessagesData();

            // Handle form submission
            form.on('submit', function (e) {
                e.preventDefault();
                loadContactMessagesData();
            });

            // Auto-filter on status change
            statusFilter.on('change', function () {
                loadContactMessagesData();
            });

            // Debounced search
            const debouncedSearch = debounce(function () {
                loadContactMessagesData();
            }, 500);

            searchInput.on('input', function () {
                debouncedSearch();
            });

            // Handle filter button (for date range)
            filterBtn.on('click', function () {
                loadContactMessagesData();
            });

            // Handle reset button
            resetBtn.on('click', function () {
                statusFilter.val('');
                dateFrom.val('');
                dateTo.val('');
                searchInput.val('');
                // Reset sort to default
                currentSortOrder = 'desc';
                const sortIcon = $('#dateSort .sort-icon');
                sortIcon.removeClass('fa-sort-up fa-sort-down active').addClass('fa-sort');
                loadContactMessagesData();
            });

            // Handle date column sort
            $('#dateSort').on('click', function () {
                // Toggle sort order
                currentSortOrder = currentSortOrder === 'desc' ? 'asc' : 'desc';

                // Update icon
                const icon = $(this).find('.sort-icon');
                icon.removeClass('fa-sort fa-sort-up fa-sort-down active');

                if (currentSortOrder === 'asc') {
                    icon.addClass('fa-sort-up active');
                } else {
                    icon.addClass('fa-sort-down active');
                }

                // Reload data with sort parameters
                loadContactMessagesData();
            });

            // Handle view message button click
            $(document).on('click', '.view-message', function () {
                const messageId = $(this).data('id');
                const url = '{{ route("admin.contact-messages.show", ":id") }}'.replace(':id', messageId);

                // Show loading state
                $('#messageDetailsContainer').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>');

                $.ajax({
                    url: url,
                    method: 'GET',
                    dataType: 'json',
                    success: function (response) {
                        if (response.error === false && response.data) {
                            const data = response.data;
                            let statusBadge = '';
                            switch (data.status) {
                                case 'new':
                                    statusBadge = '<span class="badge badge-warning">' + data.status_label + '</span>';
                                    break;
                                case 'read':
                                    statusBadge = '<span class="badge badge-info">' + data.status_label + '</span>';
                                    break;
                                case 'replied':
                                    statusBadge = '<span class="badge badge-success">' + data.status_label + '</span>';
                                    break;
                                case 'closed':
                                    statusBadge = '<span class="badge badge-secondary">' + data.status_label + '</span>';
                                    break;
                            }

                            const html = `
                                <div class="contact-message-details">
                                    <!-- Contact Info Card -->
                                    <div class="detail-card">
                                        <div class="detail-card-header">
                                            <i class="fas fa-user-circle"></i>
                                            <span>{{ __('Contact Information') }}</span>
                                        </div>
                                        <div class="detail-card-body">
                                            <div class="info-row">
                                                <div class="info-icon"><i class="fas fa-user"></i></div>
                                                <div class="info-content">
                                                    <div class="info-label">{{ __('Name') }}</div>
                                                    <div class="info-value">${data.first_name}</div>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-icon"><i class="fas fa-envelope"></i></div>
                                                <div class="info-content">
                                                    <div class="info-label">{{ __('Email') }}</div>
                                                    <div class="info-value"><a href="mailto:${data.email}">${data.email}</a></div>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-icon"><i class="fas fa-calendar"></i></div>
                                                <div class="info-content">
                                                    <div class="info-label">{{ __('Submitted On') }}</div>
                                                    <div class="info-value">${data.created_at}</div>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-icon"><i class="fas fa-info-circle"></i></div>
                                                <div class="info-content">
                                                    <div class="info-label">{{ __('Status') }}</div>
                                                    <div class="info-value">${statusBadge}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Message Card -->
                                    <div class="detail-card">
                                        <div class="detail-card-header">
                                            <i class="fas fa-comment-dots"></i>
                                            <span>{{ __('Message') }}</span>
                                        </div>
                                        <div class="detail-card-body">
                                            <div class="message-content">${data.message}</div>
                                        </div>
                                    </div>

                                    <!-- Technical Details Card -->
                                    <div class="detail-card">
                                        <div class="detail-card-header">
                                            <i class="fas fa-cog"></i>
                                            <span>{{ __('Technical Details') }}</span>
                                        </div>
                                        <div class="detail-card-body">
                                            <div class="info-row">
                                                <div class="info-icon"><i class="fas fa-hashtag"></i></div>
                                                <div class="info-content">
                                                    <div class="info-label">{{ __('Message ID') }}</div>
                                                    <div class="info-value">#${data.id}</div>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-icon"><i class="fas fa-network-wired"></i></div>
                                                <div class="info-content">
                                                    <div class="info-label">{{ __('IP Address') }}</div>
                                                    <div class="info-value">${data.ip_address || 'N/A'}</div>
                                                </div>
                                            </div>
                                            <div class="info-row">
                                                <div class="info-icon"><i class="fas fa-desktop"></i></div>
                                                <div class="info-content">
                                                    <div class="info-label">{{ __('User Agent') }}</div>
                                                    <div class="info-value text-muted"><small>${data.user_agent || 'N/A'}</small></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            `;
                            $('#messageDetailsContainer').html(html);

                            // Reload table and statistics to update status badge (if it was marked as read)
                            loadContactMessagesData();
                            reloadStatistics();
                        } else {
                            $('#messageDetailsContainer').html('<div class="text-center py-4 text-danger">{{ __("Error loading message details.") }}</div>');
                        }
                    },
                    error: function (xhr) {
                        console.error('Failed to load message details:', xhr);
                        $('#messageDetailsContainer').html('<div class="text-center py-4 text-danger">{{ __("Error loading message details.") }}</div>');
                    }
                });
            });

            // Handle update status button click
            $(document).on('click', '.update-status', function () {
                const messageId = $(this).data('id');
                const currentStatus = $(this).data('status');
                const updateUrl = '{{ route("admin.contact-messages.update-status", ":id") }}'.replace(':id', messageId);

                $('#updateStatusForm').attr('action', updateUrl);
                $('#statusSelect').val(currentStatus);
            });

            // Handle status update form submission
            $('#updateStatusForm').on('submit', function (e) {
                e.preventDefault();
                const form = $(this);
                const url = form.attr('action');
                const formData = form.serialize();

                $.ajax({
                    url: url,
                    method: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function (response) {
                        if (response.error === false) {
                            $('#updateStatusModal').modal('hide');
                            loadContactMessagesData();
                            reloadStatistics();

                            if (typeof showSuccessToast === 'function') {
                                showSuccessToast(response.message || '{{ __("Status updated successfully") }}');
                            } else {
                                alert(response.message || '{{ __("Status updated successfully") }}');
                            }
                        } else {
                            if (typeof showErrorToast === 'function') {
                                showErrorToast(response.message || '{{ __("Failed to update status") }}');
                            } else {
                                alert(response.message || '{{ __("Failed to update status") }}');
                            }
                        }
                    },
                    error: function (xhr) {
                        console.error('Failed to update status:', xhr);
                        let errorMsg = '{{ __("An error occurred. Please try again.") }}';

                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }

                        if (typeof showErrorToast === 'function') {
                            showErrorToast(errorMsg);
                        } else {
                            alert(errorMsg);
                        }
                    }
                });
            });

            // Handle delete button click
            $(document).on('click', '.delete-message', function (e) {
                e.preventDefault();
                const messageId = $(this).data('id');
                const deleteUrl = '{{ route("admin.contact-messages.destroy", ":id") }}'.replace(':id', messageId);

                Swal.fire({
                    title: '{{ __("Are you sure?") }}',
                    text: '{{ __("You won\'t be able to revert this!") }}',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: '{{ __("Yes, delete it!") }}',
                    cancelButtonText: '{{ __("Cancel") }}'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: deleteUrl,
                            method: 'DELETE',
                            data: {
                                _token: '{{ csrf_token() }}'
                            },
                            dataType: 'json',
                            success: function (response) {
                                if (response.error === false) {
                                    loadContactMessagesData();
                                    reloadStatistics();

                                    if (typeof showSuccessToast === 'function') {
                                        showSuccessToast(response.message || '{{ __("Message deleted successfully") }}');
                                    } else {
                                        Swal.fire(
                                            '{{ __("Deleted!") }}',
                                            response.message || '{{ __("Message deleted successfully") }}',
                                            'success'
                                        );
                                    }
                                } else {
                                    if (typeof showErrorToast === 'function') {
                                        showErrorToast(response.message || '{{ __("Failed to delete message") }}');
                                    } else {
                                        Swal.fire(
                                            '{{ __("Error!") }}',
                                            response.message || '{{ __("Failed to delete message") }}',
                                            'error'
                                        );
                                    }
                                }
                            },
                            error: function (xhr) {
                                console.error('Failed to delete message:', xhr);
                                let errorMsg = '{{ __("An error occurred. Please try again.") }}';

                                if (xhr.responseJSON && xhr.responseJSON.message) {
                                    errorMsg = xhr.responseJSON.message;
                                }

                                if (typeof showErrorToast === 'function') {
                                    showErrorToast(errorMsg);
                                } else {
                                    Swal.fire(
                                        '{{ __("Error!") }}',
                                        errorMsg,
                                        'error'
                                    );
                                }
                            }
                        });
                    }
                });
            });
        });
    </script>
@endsection

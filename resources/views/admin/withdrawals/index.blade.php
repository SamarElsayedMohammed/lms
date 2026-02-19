@extends('layouts.app')

@section('title')
    {{ __('Withdrawal Requests') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
@endsection

@section('main')
    @php
        try {
            $currencyCode = \App\Services\HelperService::systemSettings('currency_code') ?? 'USD';
            $currencyData = \App\Services\HelperService::getCurrencyData($currencyCode);
            $currencySymbol = $currencyData['symbol'] ?? '$';
        } catch (\Exception $e) {
            $currencySymbol = '$';
        }
    @endphp
    <div class="content-wrapper">
        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-primary">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Requests') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ \App\Models\WithdrawalRequest::where('entry_type', 'user')->count() }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-warning">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Pending') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ \App\Models\WithdrawalRequest::where('status', 'pending')->where('entry_type', 'user')->count() }}
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
                            <h4>{{ __('Approved') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ \App\Models\WithdrawalRequest::where('status', 'approved')->where('entry_type', 'user')->count() }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-danger">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Rejected') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ \App\Models\WithdrawalRequest::where('status', 'rejected')->where('entry_type', 'user')->count() }}
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
                        <h4 class="card-title">{{ __('Withdrawal Requests') }}</h4>

                        <!-- Filter and Search -->
                        <form id="withdrawalSearchForm" method="GET" action="{{ route('admin.withdrawals.index') }}" class="mb-4">
                            <div class="row align-items-end g-3">
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label text-muted small mb-1">{{ __('Status') }}</label>
                                    <select name="status" id="statusFilter" class="form-control">
                                        <option value="">{{ __('All Statuses') }}</option>
                                        <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                                        <option value="approved" {{ request('status') == 'approved' ? 'selected' : '' }}>{{ __('Approved') }}</option>
                                        <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>{{ __('Rejected') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label text-muted small mb-1">{{ __('Date From') }}</label>
                                    <input type="date" name="date_from" id="dateFrom" class="form-control" value="{{ request('date_from') }}">
                                </div>
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label text-muted small mb-1">{{ __('Date To') }}</label>
                                    <input type="date" name="date_to" id="dateTo" class="form-control" value="{{ request('date_to') }}">
                                </div>
                                <div class="col-md-4 col-sm-8">
                                    <label class="form-label text-muted small mb-1">{{ __('Search') }}</label>
                                    <input type="text" name="search" id="searchInput" class="form-control" placeholder="{{ __('Search by user name or email...') }}" value="{{ request('search') }}">
                                </div>
                                <div class="col-md-2 col-sm-4 d-flex align-items-end justify-content-end">
                                    <div class="d-flex w-100 gap-2">
                                        <button type="submit" class="btn btn-primary flex-fill" id="searchBtn">
                                            <i class="fas fa-search mr-2"></i>{{ __('Search') }}
                                        </button>
                                        <button type="button" class="btn btn-secondary flex-fill" id="resetBtn">
                                            <i class="fas fa-sync-alt mr-2"></i>{{ __('Reset') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <!-- Withdrawal Requests Table -->
                        <div class="table-responsive">
                            <table class="table table-striped" id="withdrawalTable">
                                <thead>
                                    <tr>
                                        <th>{{ __('ID') }}</th>
                                        <th>{{ __('User') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('Payment Method') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Entry Type') }}</th>
                                        <th>{{ __('Requested Date') }}</th>
                                        <th>{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="withdrawalTableBody">
                                    <!-- Data will be loaded via AJAX -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1" role="dialog" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updateStatusModalLabel">{{ __('Update Withdrawal Request Status') }}</h5>
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
                                <option value="pending">{{ __('Pending') }}</option>
                                <option value="approved">{{ __('Approved') }}</option>
                                <option value="rejected">{{ __('Rejected') }}</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="adminNotes">{{ __('Admin Notes') }} <span id="adminNotesRequired" class="text-danger" style="display: none;">*</span></label>
                            <textarea name="admin_notes" id="adminNotes" class="form-control" rows="3" placeholder="{{ __('Enter admin notes') }}"></textarea>
                            <small class="form-text text-muted" id="adminNotesHelp">{{ __('Admin notes are required when rejecting a withdrawal request.') }}</small>
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

@section('style')
<style>
    #statusFilter,
    #dateFrom,
    #dateTo,
    #searchInput {
        height: 38px;
    }
    @media (max-width: 767.98px) {
        #withdrawalSearchForm .d-flex {
            flex-direction: column;
        }
        #withdrawalSearchForm .d-flex .btn {
            width: 100%;
        }
    }
</style>
@endsection

@section('script')
<script>
    $(document).ready(function() {
        // Declare variables first
        const form = $('#withdrawalSearchForm');
        const tableBody = $('#withdrawalTableBody');
        const searchBtn = $('#searchBtn');
        const resetBtn = $('#resetBtn');

        // Define loadWithdrawalData function after variables are declared
        const loadWithdrawalData = function() {
            const formData = form.serialize();
            const url = '{{ route("admin.withdrawals.data") }}?' + formData;

            // Show loading state
            const originalHtml = searchBtn.html();
            searchBtn.prop('disabled', true)
                .html('<i class="fas fa-spinner fa-spin"></i>');

            tableBody.html('<tr><td colspan="8" class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> {{ __("Loading...") }}</td></tr>');

            $.ajax({
                url: url,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    console.log('Response:', response); // Debug log
                    if ((response.error === false || response.status === 'success') && response.data) {
                        let html = '';
                        if (response.data.length > 0) {
                            response.data.forEach(function(row) {
                                let statusBadge = '';
                                switch(row.status.toLowerCase()) {
                                    case 'pending':
                                        statusBadge = '<span class="badge badge-warning">' + row.status + '</span>';
                                        break;
                                    case 'approved':
                                        statusBadge = '<span class="badge badge-success">' + row.status + '</span>';
                                        break;
                                    case 'rejected':
                                        statusBadge = '<span class="badge badge-danger">' + row.status + '</span>';
                                        break;
                                    default:
                                        statusBadge = '<span class="badge badge-secondary">' + row.status + '</span>';
                                }

                                html += '<tr>';
                                html += '<td>#' + row.id + '</td>';
                                html += '<td><div><strong>' + row.user_name + '</strong><br><small class="text-muted">' + row.user_email + '</small></div></td>';
                                html += '<td><strong class="text-success">{{ $currencySymbol }}' + row.amount + '</strong></td>';
                                html += '<td>' + row.payment_method + '</td>';
                                html += '<td>' + statusBadge + '</td>';
                                const entryTypeBadge = row.entry_type === 'User' ? 'primary' : (row.entry_type === 'Instructor' ? 'warning' : 'info');
                                html += '<td><span class="badge badge-' + entryTypeBadge + '">' + row.entry_type + '</span></td>';
                                html += '<td>' + row.created_at + '</td>';
                                html += '<td>' + row.operate + '</td>';
                                html += '</tr>';
                            });
                        } else {
                            html = '<tr><td colspan="8" class="text-center py-4"><div class="empty-state"><i class="fas fa-money-bill-wave fa-3x text-muted mb-3"></i><h5>{{ __("No withdrawal requests found") }}</h5><p class="text-muted">{{ __("There are no withdrawal requests matching your criteria.") }}</p></div></td></tr>';
                        }
                        tableBody.html(html);
                    } else {
                        tableBody.html('<tr><td colspan="8" class="text-center py-4 text-danger">{{ __("Error loading data. Please try again.") }}</td></tr>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load withdrawal data:', {xhr: xhr, status: status, error: error, responseText: xhr.responseText});
                    let errorMsg = '{{ __("An error occurred while loading data. Please try again.") }}';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    tableBody.html('<tr><td colspan="8" class="text-center py-4 text-danger">' + errorMsg + '<br><small>' + error + '</small></td></tr>');
                },
                complete: function() {
                    searchBtn.prop('disabled', false).html(originalHtml);
                }
            });
        };

        // Now call the function after it's defined
        loadWithdrawalData();

        // Handle form submission
        form.on('submit', function(e) {
            e.preventDefault();
            loadWithdrawalData();
        });

        // Handle reset button
        resetBtn.on('click', function() {
            $('#statusFilter').val('');
            $('#dateFrom').val('');
            $('#dateTo').val('');
            $('#searchInput').val('');
            loadWithdrawalData();
        });

        // Handle edit button click to open modal
        $(document).on('click', '.edit-data', function() {
            const withdrawalId = $(this).data('id');
            const updateUrl = '{{ route("admin.withdrawals.update-status", ":id") }}'.replace(':id', withdrawalId);
            $('#updateStatusForm').attr('action', updateUrl);
            // Reset form
            $('#statusSelect').val('pending');
            $('#adminNotes').val('').removeAttr('required');
            $('#adminNotesRequired').hide();
        });

        // Handle status change to make admin notes required for rejected
        $('#statusSelect').on('change', function() {
            const status = $(this).val();
            const adminNotes = $('#adminNotes');
            const adminNotesRequired = $('#adminNotesRequired');
            
            if (status === 'rejected') {
                adminNotes.attr('required', 'required');
                adminNotesRequired.show();
            } else {
                adminNotes.removeAttr('required');
                adminNotesRequired.hide();
            }
        });

        // Handle form submission
        $('#updateStatusForm').on('submit', function(e) {
            e.preventDefault();
            const form = $(this);
            const url = form.attr('action');
            const formData = form.serialize();
            const status = $('#statusSelect').val();
            const adminNotes = $('#adminNotes').val().trim();

            // Client-side validation for rejected status
            if (status === 'rejected' && !adminNotes) {
                if (typeof showErrorToast === 'function') {
                    showErrorToast('{{ __("Admin notes are required when rejecting a withdrawal request.") }}');
                } else {
                    alert('{{ __("Admin notes are required when rejecting a withdrawal request.") }}');
                }
                return false;
            }

            $.ajax({
                url: url,
                method: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.error === false) {
                        $('#updateStatusModal').modal('hide');
                        loadWithdrawalData();
                        
                        // Show success toast
                        if (typeof showSuccessToast === 'function') {
                            showSuccessToast(response.message || '{{ __("Status updated successfully") }}');
                        } else {
                            alert(response.message || '{{ __("Status updated successfully") }}');
                        }
                    } else {
                        // Show error toast
                        if (typeof showErrorToast === 'function') {
                            showErrorToast(response.message || '{{ __("Failed to update status") }}');
                        } else {
                            alert(response.message || '{{ __("Failed to update status") }}');
                        }
                    }
                },
                error: function(xhr) {
                    console.error('Failed to update status:', xhr);
                    let errorMsg = '{{ __("An error occurred. Please try again.") }}';
                    
                    if (xhr.responseJSON) {
                        if (xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        } else if (xhr.responseJSON.errors) {
                            const errors = xhr.responseJSON.errors;
                            errorMsg = Object.values(errors).flat().join(', ');
                        }
                    }
                    
                    // Show error toast
                    if (typeof showErrorToast === 'function') {
                        showErrorToast(errorMsg);
                    } else {
                        alert(errorMsg);
                    }
                }
            });
        });
    });
</script>
@endsection

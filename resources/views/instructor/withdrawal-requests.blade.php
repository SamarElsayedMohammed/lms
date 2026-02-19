@extends('layouts.app')

@section('title')
    {{ __('Withdrawal Requests') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
@endsection

@section('main')
    <div class="content-wrapper">
        <!-- Table List -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('Withdrawal Requests') }}
                        </h4>

                        <!-- Filter Section -->
                        <div class="row mb-3 g-3 align-items-end" id="filter-section" style="display: none;">
                            <div class="col-md-3 col-sm-6">
                                <select class="form-control" id="status-filter">
                                    <option value="">{{ __('All Status') }}</option>
                                    <option value="pending">{{ __('Pending') }}</option>
                                    <option value="approved">{{ __('Approved') }}</option>
                                    <option value="rejected">{{ __('Rejected') }}</option>
                                    <option value="processing">{{ __('Processing') }}</option>
                                    <option value="completed">{{ __('Completed') }}</option>
                                </select>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <select class="form-control" id="instructor-filter">
                                    <option value="">{{ __('All Supervisors') }}</option>
                                    @foreach($instructors as $instructor)
                                        <option value="{{ $instructor->id }}">{{ $instructor->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <input type="text" class="form-control" id="search-input" placeholder="{{ __('Search...') }}">
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="d-flex w-100 gap-2 flex-wrap">
                                    <button class="btn btn-primary flex-fill" id="apply-filter">
                                        <i class="fas fa-search mr-2"></i>{{ __('Apply Filter') }}
                                    </button>
                                    <button class="btn btn-secondary flex-fill" id="clear-filter">
                                        <i class="fas fa-sync-alt mr-2"></i>{{ __('Clear') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Summary Cards -->
                        <div class="row mb-4">
                            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                                <div class="card card-statistic-1">
                                    <div class="card-icon bg-warning">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="card-wrap">
                                        <div class="card-header">
                                            <h4>{{ __('Pending') }}</h4>
                                        </div>
                                        <div class="card-body" id="pending-count">
                                            -
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                                <div class="card card-statistic-1">
                                    <div class="card-icon bg-success">
                                        <i class="fas fa-check"></i>
                                    </div>
                                    <div class="card-wrap">
                                        <div class="card-header">
                                            <h4>{{ __('Approved') }}</h4>
                                        </div>
                                        <div class="card-body" id="approved-count">
                                            -
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                                <div class="card card-statistic-1">
                                    <div class="card-icon bg-danger">
                                        <i class="fas fa-times"></i>
                                    </div>
                                    <div class="card-wrap">
                                        <div class="card-header">
                                            <h4>{{ __('Rejected') }}</h4>
                                        </div>
                                        <div class="card-body" id="rejected-count">
                                            -
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                                <div class="card card-statistic-1">
                                    <div class="card-icon bg-primary">
                                        <i class="fas fa-rupee-sign"></i>
                                    </div>
                                    <div class="card-wrap">
                                        <div class="card-header">
                                            <h4>{{ __('Total Amount') }}</h4>
                                        </div>
                                        <div class="card-body" id="total-amount">
                                            -
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-outline-primary" id="filter-btn">
                                        <i class="fas fa-filter mr-2"></i> {{ __('Filter') }}
                                    </button>
                                    <button class="btn btn-outline-success" id="refresh-btn">
                                        <i class="fas fa-sync mr-2"></i> {{ __('Refresh') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Data Table -->
                        <div class="table-responsive">
                            <table class="table table-striped" id="withdrawal-requests-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('No') }}</th>
                                        <th>{{ __('Supervisor') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Payment Method') }}</th>
                                        <th>{{ __('Notes') }}</th>
                                        <th>{{ __('Created At') }}</th>
                                        <th>{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Data will be loaded via AJAX -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="dataTables_info" id="table-info">
                                {{ __('Showing 0 to 0 of 0 entries') }}
                            </div>
                            <div class="dataTables_paginate paging_simple_numbers" id="table-pagination">
                                <!-- Pagination will be loaded here -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal fade" id="view-details-modal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Withdrawal Request Details') }}</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="modal-body">
                    <!-- Details will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Close') }}</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal fade" id="status-update-modal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Update Status') }}</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <form id="status-update-form">
                    <div class="modal-body">
                        <input type="hidden" id="withdrawal-request-id" name="withdrawal_request_id">
                        <div class="form-group">
                            <label>{{ __('Status') }}</label>
                            <select class="form-control" id="new-status" name="status" required>
                                <option value="pending">{{ __('Pending') }}</option>
                                <option value="approved">{{ __('Approved') }}</option>
                                <option value="rejected">{{ __('Rejected') }}</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>{{ __('Admin Notes') }}</label>
                            <textarea class="form-control" id="admin-notes" name="admin_notes" rows="3" placeholder="{{ __('Add notes about this status change...') }}"></textarea>
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

@push('scripts')
<script>
$(document).ready(function() {
    let currentPage = 1;
    let currentLimit = 10;
    let currentSearch = '';
    let currentStatus = '';
    let currentInstructor = '';

    // Initialize DataTable
    loadWithdrawalRequests();

    // Filter toggle
    $('#filter-btn').click(function() {
        $('#filter-section').toggle();
    });

    // Apply filter
    $('#apply-filter').click(function() {
        currentStatus = $('#status-filter').val();
        currentInstructor = $('#instructor-filter').val();
        currentSearch = $('#search-input').val();
        currentPage = 1;
        loadWithdrawalRequests();
    });

    // Clear filter
    $('#clear-filter').click(function() {
        $('#status-filter').val('');
        $('#instructor-filter').val('');
        $('#search-input').val('');
        currentStatus = '';
        currentInstructor = '';
        currentSearch = '';
        currentPage = 1;
        loadWithdrawalRequests();
    });

    // Refresh
    $('#refresh-btn').click(function() {
        loadWithdrawalRequests();
    });

    // Load withdrawal requests
    function loadWithdrawalRequests() {
        $.ajax({
            url: '{{ route("instructor.withdrawal-requests.data") }}',
            method: 'GET',
            data: {
                limit: currentLimit,
                offset: (currentPage - 1) * currentLimit,
                search: currentSearch,
                status: currentStatus,
                instructor_id: currentInstructor,
                sort: 'created_at',
                order: 'desc'
            },
            success: function(response) {
                updateTable(response.rows);
                updatePagination(response.total);
                updateSummary();
            },
            error: function(xhr) {
                console.error('Error loading withdrawal requests:', xhr);
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer)
                        toast.addEventListener('mouseleave', Swal.resumeTimer)
                    }
                });
                
                Toast.fire({
                    icon: 'error',
                    title: 'Failed to load withdrawal requests'
                });
            }
        });
    }

    // Update table
    function updateTable(rows) {
        const tbody = $('#withdrawal-requests-table tbody');
        tbody.empty();

        if (rows.length === 0) {
            tbody.append('<tr><td colspan="8" class="text-center">No withdrawal requests found</td></tr>');
            return;
        }

        rows.forEach(function(row) {
            const tr = $('<tr>');
            tr.append('<td>' + row.no + '</td>');
            tr.append('<td><div><strong>' + row.instructor_name + '</strong><br><small class="text-muted">' + row.instructor_email + '</small></div></td>');
            tr.append('<td>' + row.amount + '</td>');
            tr.append('<td>' + row.status + '</td>');
            tr.append('<td>' + row.payment_method + '</td>');
            tr.append('<td>' + row.notes + '</td>');
            tr.append('<td>' + row.created_at + '</td>');
            tr.append('<td>' + row.actions + '</td>');
            tbody.append(tr);
        });
    }

    // Update pagination
    function updatePagination(total) {
        const totalPages = Math.ceil(total / currentLimit);
        const start = (currentPage - 1) * currentLimit + 1;
        const end = Math.min(currentPage * currentLimit, total);

        $('#table-info').text(`Showing ${start} to ${end} of ${total} entries`);

        let paginationHtml = '<ul class="pagination">';
        
        // Previous button
        if (currentPage > 1) {
            paginationHtml += '<li class="page-item"><a class="page-link" href="#" data-page="' + (currentPage - 1) + '">Previous</a></li>';
        }

        // Page numbers
        for (let i = 1; i <= totalPages; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            paginationHtml += '<li class="page-item ' + activeClass + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
        }

        // Next button
        if (currentPage < totalPages) {
            paginationHtml += '<li class="page-item"><a class="page-link" href="#" data-page="' + (currentPage + 1) + '">Next</a></li>';
        }

        paginationHtml += '</ul>';
        $('#table-pagination').html(paginationHtml);
    }

    // Update summary
    function updateSummary() {
        $.ajax({
            url: '{{ route("instructor.withdrawal-requests.data") }}',
            method: 'GET',
            data: {
                action: 'summary'
            },
            success: function(response) {
                if (response.success && response.data) {
                    $('#pending-count').text(response.data.pending_count || 0);
                    $('#approved-count').text(response.data.approved_count || 0);
                    $('#rejected-count').text(response.data.rejected_count || 0);
                    $('#total-amount').text('₹' + parseFloat(response.data.total_amount || 0).toFixed(2));
                } else {
                    $('#pending-count').text('0');
                    $('#approved-count').text('0');
                    $('#rejected-count').text('0');
                    $('#total-amount').text('₹0.00');
                }
            },
            error: function(xhr) {
                console.error('Error loading summary data:', xhr);
                $('#pending-count').text('0');
                $('#approved-count').text('0');
                $('#rejected-count').text('0');
                $('#total-amount').text('₹0.00');
            }
        });
    }

    // Pagination click
    $(document).on('click', '.page-link', function(e) {
        e.preventDefault();
        const page = $(this).data('page');
        if (page && page !== currentPage) {
            currentPage = page;
            loadWithdrawalRequests();
        }
    });

    // View details
    $(document).on('click', '.view-details', function() {
        const id = $(this).data('id');
        loadWithdrawalRequestDetails(id);
    });

    // Load withdrawal request details
    function loadWithdrawalRequestDetails(id) {
        $.ajax({
            url: '{{ route("instructor.withdrawal-requests.data") }}',
            method: 'GET',
            data: {
                withdrawal_request_id: id,
                action: 'details'
            },
            success: function(response) {
                if (response.success && response.data) {
                    displayWithdrawalRequestDetails(response.data);
                    $('#view-details-modal').modal('show');
                } else {
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true,
                        didOpen: (toast) => {
                            toast.addEventListener('mouseenter', Swal.stopTimer)
                            toast.addEventListener('mouseleave', Swal.resumeTimer)
                        }
                    });
                    
                    Toast.fire({
                        icon: 'error',
                        title: 'Failed to load withdrawal request details'
                    });
                }
            },
            error: function(xhr) {
                console.error('Error loading withdrawal request details:', xhr);
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer)
                        toast.addEventListener('mouseleave', Swal.resumeTimer)
                    }
                });
                
                Toast.fire({
                    icon: 'error',
                    title: 'Failed to load withdrawal request details'
                });
            }
        });
    }

    // Display withdrawal request details in modal
    function displayWithdrawalRequestDetails(data) {
        const modalBody = $('#modal-body');
        modalBody.html(`
            <div class="row">
                <div class="col-md-6">
                    <h6><strong>Request ID:</strong></h6>
                    <p>#${data.id}</p>
                </div>
                <div class="col-md-6">
                    <h6><strong>Status:</strong></h6>
                    <p>${data.status_badge || data.status}</p>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <h6><strong>Supervisor:</strong></h6>
                    <p>${data.instructor_name} (${data.instructor_email})</p>
                </div>
                <div class="col-md-6">
                    <h6><strong>Amount:</strong></h6>
                    <p>₹${data.amount}</p>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <h6><strong>Payment Method:</strong></h6>
                    <p>${data.payment_method_label}</p>
                </div>
                <div class="col-md-6">
                    <h6><strong>Created At:</strong></h6>
                    <p>${data.created_at}</p>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <h6><strong>Payment Details:</strong></h6>
                    <div class="card">
                        <div class="card-body">
                            ${formatPaymentDetails(data.payment_details)}
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <h6><strong>Notes:</strong></h6>
                    <p>${data.notes || 'No notes provided'}</p>
                </div>
                <div class="col-md-6">
                    <h6><strong>Admin Notes:</strong></h6>
                    <p>${data.admin_notes || 'No admin notes'}</p>
                </div>
            </div>
            ${data.processed_at ? `
            <div class="row">
                <div class="col-md-6">
                    <h6><strong>Processed At:</strong></h6>
                    <p>${data.processed_at}</p>
                </div>
                <div class="col-md-6">
                    <h6><strong>Processed By:</strong></h6>
                    <p>${data.processed_by || 'N/A'}</p>
                </div>
            </div>
            ` : ''}
        `);
    }

    // Format payment details
    function formatPaymentDetails(paymentDetails) {
        if (!paymentDetails) return '<p>No payment details available</p>';
        
        let html = '<ul class="list-unstyled">';
        for (const [key, value] of Object.entries(paymentDetails)) {
            const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            html += `<li><strong>${label}:</strong> ${value}</li>`;
        }
        html += '</ul>';
        return html;
    }

    // Status update buttons
    $(document).on('click', '.approve-request, .reject-request, .process-request, .complete-request', function() {
        const id = $(this).data('id');
        const action = $(this).hasClass('approve-request') ? 'approved' :
                      $(this).hasClass('reject-request') ? 'rejected' :
                      $(this).hasClass('process-request') ? 'processing' : 'completed';
        
        $('#withdrawal-request-id').val(id);
        $('#new-status').val(action);
        $('#admin-notes').val('');
        $('#status-update-modal').modal('show');
    });

    // Status update form
    $('#status-update-form').submit(function(e) {
        e.preventDefault();
        
        
        $.ajax({
            url: '{{ route("instructor.withdrawal-request.update-status") }}',
            method: 'POST',
            data: $(this).serialize(),
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                if (response.error === false || response.success === true) {
                    // Auto-hide modal
                    $('#status-update-modal').modal('hide');
                    
                    // Refresh data
                    loadWithdrawalRequests();
                    
                // Show success SweetAlert toast
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer)
                        toast.addEventListener('mouseleave', Swal.resumeTimer)
                    }
                });
                
                Toast.fire({
                    icon: 'success',
                    title: response.message || 'Status updated successfully'
                });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: response.message,
                        confirmButtonText: 'OK'
                    });
                }
            },
            error: function(xhr) {
                const response = xhr.responseJSON;
                let errorMessage = response.message || 'Failed to update status';
                
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: errorMessage,
                    confirmButtonText: 'OK'
                });
            }
        });
    });
});
</script>
@endpush

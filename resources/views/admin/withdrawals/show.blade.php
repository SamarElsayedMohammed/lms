@extends('layouts.app')

@section('title')
    {{ __('Withdrawal Request Details') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a href="{{ route('admin.withdrawals.index') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> {{ __('Back to List') }}
        </a>
    </div>
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
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h4 class="card-title mb-0">{{ __('Withdrawal Request') }} #{{ $withdrawal->id }}</h4>
                            @if($withdrawal->status == 'pending')
                                <span class="badge badge-warning">{{ __('Pending Review') }}</span>
                            @elseif($withdrawal->status == 'approved')
                                <span class="badge badge-success">{{ __('Approved') }}</span>
                            @elseif($withdrawal->status == 'rejected')
                                <span class="badge badge-danger">{{ __('Rejected') }}</span>
                            @elseif($withdrawal->status == 'processing')
                                <span class="badge badge-info">{{ __('Processing') }}</span>
                            @elseif($withdrawal->status == 'completed')
                                <span class="badge badge-success">{{ __('Completed') }}</span>
                            @endif
                        </div>

                        <!-- User Information -->
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-user mr-2"></i>{{ __('User Information') }}
                                </h6>
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <td width="180"><strong>{{ __('Name:') }}</strong></td>
                                        <td>{{ $withdrawal->user->name ?? 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Email:') }}</strong></td>
                                        <td>{{ $withdrawal->user->email ?? 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Wallet Balance:') }}</strong></td>
                                        <td class="text-success font-weight-bold">{{ $currencySymbol }}{{ number_format($withdrawal->user->wallet_balance ?? 0, 2) }}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- Withdrawal Details -->
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-info-circle mr-2"></i>{{ __('Withdrawal Details') }}
                                </h6>
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <td width="180"><strong>{{ __('Amount:') }}</strong></td>
                                        <td><span class="h5 text-success font-weight-bold">{{ $currencySymbol }}{{ number_format($withdrawal->amount, 2) }}</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Payment Method:') }}</strong></td>
                                        <td>{{ ucwords(str_replace('_', ' ', $withdrawal->payment_method)) }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Status:') }}</strong></td>
                                        <td>
                                            @if($withdrawal->status == 'pending')
                                                <span class="badge badge-warning">{{ __('Pending') }}</span>
                                            @elseif($withdrawal->status == 'approved')
                                                <span class="badge badge-success">{{ __('Approved') }}</span>
                                            @elseif($withdrawal->status == 'rejected')
                                                <span class="badge badge-danger">{{ __('Rejected') }}</span>
                                            @elseif($withdrawal->status == 'processing')
                                                <span class="badge badge-info">{{ __('Processing') }}</span>
                                            @elseif($withdrawal->status == 'completed')
                                                <span class="badge badge-success">{{ __('Completed') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Entry Type:') }}</strong></td>
                                        <td>
                                            @if($withdrawal->entry_type == 'user')
                                                <span class="badge badge-primary">{{ __('User') }}</span>
                                            @elseif($withdrawal->entry_type == 'instructor')
                                                <span class="badge badge-warning">{{ __('Instructor') }}</span>
                                            @elseif($withdrawal->entry_type == 'staff')
                                                <span class="badge badge-info">{{ __('Staff') }}</span>
                                            @else
                                                <span class="badge badge-secondary">{{ ucfirst($withdrawal->entry_type ?? 'User') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Requested Date:') }}</strong></td>
                                        <td>{{ $withdrawal->created_at->format('M d, Y h:i A') }}</td>
                                    </tr>
                                    @if($withdrawal->processed_at)
                                    <tr>
                                        <td><strong>{{ __('Processed Date:') }}</strong></td>
                                        <td>{{ $withdrawal->processed_at->format('M d, Y h:i A') }}</td>
                                    </tr>
                                    @endif
                                    @if($withdrawal->processedByUser)
                                    <tr>
                                        <td><strong>{{ __('Processed By:') }}</strong></td>
                                        <td>{{ $withdrawal->processedByUser->name ?? 'N/A' }}</td>
                                    </tr>
                                    @endif
                                </table>
                            </div>
                        </div>

                        <!-- Payment Details -->
                        @if($withdrawal->payment_details)
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-credit-card mr-2"></i>{{ __('Payment Details') }}
                                </h6>
                                <div class="card bg-light">
                                    <div class="card-body">
                                        @if($withdrawal->payment_method == 'bank_transfer')
                                            <table class="table table-borderless table-sm mb-0">
                                                <tr>
                                                    <td width="200"><strong>{{ __('Account Holder Name:') }}</strong></td>
                                                    <td>{{ $withdrawal->payment_details['account_holder_name'] ?? 'N/A' }}</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>{{ __('Account Number:') }}</strong></td>
                                                    <td>{{ $withdrawal->payment_details['account_number'] ?? 'N/A' }}</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>{{ __('Bank Name:') }}</strong></td>
                                                    <td>{{ $withdrawal->payment_details['bank_name'] ?? 'N/A' }}</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>{{ __('IFSC Code:') }}</strong></td>
                                                    <td>{{ $withdrawal->payment_details['ifsc_code'] ?? 'N/A' }}</td>
                                                </tr>
                                            </table>
                                        @elseif($withdrawal->payment_method == 'paypal')
                                            <table class="table table-borderless table-sm mb-0">
                                                <tr>
                                                    <td width="200"><strong>{{ __('PayPal Email:') }}</strong></td>
                                                    <td>{{ $withdrawal->payment_details['paypal_email'] ?? 'N/A' }}</td>
                                                </tr>
                                            </table>
                                        @else
                                            <pre>{{ json_encode($withdrawal->payment_details, JSON_PRETTY_PRINT) }}</pre>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- Notes -->
                        @if($withdrawal->notes)
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-sticky-note mr-2"></i>{{ __('User Notes') }}
                                </h6>
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <p class="mb-0">{{ $withdrawal->notes }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- Admin Notes -->
                        @if($withdrawal->admin_notes)
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-comment-alt mr-2"></i>{{ __('Admin Notes') }}
                                </h6>
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <p class="mb-0">{{ $withdrawal->admin_notes }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- Action Buttons -->
                        @if($withdrawal->status == 'pending')
                        <div class="row">
                            <div class="col-md-12">
                                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#updateStatusModal">
                                    <i class="fas fa-edit mr-2"></i>{{ __('Update Status') }}
                                </button>
                            </div>
                        </div>
                        @endif
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
                <form id="updateStatusForm" method="POST" action="{{ route('admin.withdrawals.update-status', $withdrawal->id) }}">
                    @csrf
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="statusSelect">{{ __('Status') }} <span class="text-danger">*</span></label>
                            <select name="status" id="statusSelect" class="form-control" required>
                                <option value="pending" {{ $withdrawal->status == 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                                <option value="approved" {{ $withdrawal->status == 'approved' ? 'selected' : '' }}>{{ __('Approved') }}</option>
                                <option value="rejected" {{ $withdrawal->status == 'rejected' ? 'selected' : '' }}>{{ __('Rejected') }}</option>
                                <option value="processing" {{ $withdrawal->status == 'processing' ? 'selected' : '' }}>{{ __('Processing') }}</option>
                                <option value="completed" {{ $withdrawal->status == 'completed' ? 'selected' : '' }}>{{ __('Completed') }}</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="adminNotes">{{ __('Admin Notes') }}</label>
                            <textarea name="admin_notes" id="adminNotes" class="form-control" rows="3" placeholder="{{ __('Enter admin notes (optional)') }}">{{ $withdrawal->admin_notes }}</textarea>
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

@section('script')
<script>
    $(document).ready(function() {
        $('#updateStatusForm').on('submit', function(e) {
            e.preventDefault();
            const form = $(this);
            const formData = form.serialize();

            $.ajax({
                url: form.attr('action'),
                method: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        $('#updateStatusModal').modal('hide');
                        location.reload();
                    } else {
                        alert(response.message || '{{ __("Failed to update status") }}');
                    }
                },
                error: function(xhr) {
                    console.error('Failed to update status:', xhr);
                    const errorMsg = xhr.responseJSON?.message || '{{ __("An error occurred. Please try again.") }}';
                    alert(errorMsg);
                }
            });
        });
    });
</script>
@endsection

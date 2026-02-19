@extends('layouts.app')

@section('title')
    {{ __('Refund Request Details') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a href="{{ route('admin.refunds.index') }}" class="btn btn-secondary">
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
                            <h4 class="card-title mb-0">{{ __('Refund Request') }} #{{ $refund->id }}</h4>
                            @if($refund->status == 'pending')
                                <span class="badge badge-warning">{{ __('Pending Review') }}</span>
                            @elseif($refund->status == 'approved')
                                <span class="badge badge-success">{{ __('Approved') }}</span>
                            @elseif($refund->status == 'rejected')
                                <span class="badge badge-danger">{{ __('Rejected') }}</span>
                            @endif
                        </div>

                        <!-- User Information -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-user mr-2"></i>{{ __('User Information') }}
                                </h6>
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <td width="120"><strong>{{ __('Name:') }}</strong></td>
                                        <td>{{ $refund->user->name ?? 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Email:') }}</strong></td>
                                        <td>{{ $refund->user->email ?? 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Mobile:') }}</strong></td>
                                        <td>{{ $refund->user->mobile ?? 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Wallet:') }}</strong></td>
                                        <td class="text-success font-weight-bold">{{ $currencySymbol }}{{ number_format($refund->user->wallet_balance ?? 0, 2) }}</td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-book mr-2"></i>{{ __('Course Information') }}
                                </h6>
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <td width="120"><strong>{{ __('Course:') }}</strong></td>
                                        <td>{{ $refund->course->title ?? 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Course ID:') }}</strong></td>
                                        <td>{{ $refund->course_id }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Price:') }}</strong></td>
                                        <td>{{ $currencySymbol }}{{ number_format($refund->course->price ?? 0, 2) }}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- Course Progress -->
                        @if($courseProgress)
                        <hr>
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-chart-line mr-2"></i>{{ __('Course Progress') }}
                                </h6>
                                
                                <!-- Progress Bar -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="font-weight-bold">{{ __('Overall Progress') }}</span>
                                        <span class="font-weight-bold">{{ $courseProgress['progress_percentage'] }}%</span>
                                    </div>
                                    <div class="progress" style="height: 25px;">
                                        <div class="progress-bar 
                                            @if($courseProgress['progress_percentage'] == 100) bg-success
                                            @elseif($courseProgress['progress_percentage'] >= 50) bg-info
                                            @elseif($courseProgress['progress_percentage'] > 0) bg-warning
                                            @else bg-secondary
                                            @endif" 
                                            role="progressbar" 
                                            style="width: {{ $courseProgress['progress_percentage'] }}%" 
                                            aria-valuenow="{{ $courseProgress['progress_percentage'] }}" 
                                            aria-valuemin="0" 
                                            aria-valuemax="100">
                                            {{ $courseProgress['progress_percentage'] }}%
                                        </div>
                                    </div>
                                </div>

                                <!-- Progress Details -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-borderless table-sm">
                                            <tr>
                                                <td width="180"><strong>{{ __('Total Chapters:') }}</strong></td>
                                                <td>{{ $courseProgress['total_chapters'] }}</td>
                                            </tr>
                                            <tr>
                                                <td><strong>{{ __('Completed Chapters:') }}</strong></td>
                                                <td class="text-success font-weight-bold">{{ $courseProgress['completed_chapters'] }}</td>
                                            </tr>
                                            <tr>
                                                <td><strong>{{ __('Remaining Chapters:') }}</strong></td>
                                                <td>{{ $courseProgress['total_chapters'] - $courseProgress['completed_chapters'] }}</td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-borderless table-sm">
                                            <tr>
                                                <td width="180"><strong>{{ __('Total Items:') }}</strong></td>
                                                <td>{{ $courseProgress['total_curriculum_items'] }}</td>
                                            </tr>
                                            <tr>
                                                <td><strong>{{ __('Completed Items:') }}</strong></td>
                                                <td class="text-success font-weight-bold">{{ $courseProgress['completed_curriculum_items'] }}</td>
                                            </tr>
                                            <tr>
                                                <td><strong>{{ __('Remaining Items:') }}</strong></td>
                                                <td>{{ $courseProgress['total_curriculum_items'] - $courseProgress['completed_curriculum_items'] }}</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>

                                <!-- Tracking Dates -->
                                @if($courseProgress['first_tracking_date'] || $courseProgress['last_completed_date'])
                                <hr>
                                <div class="row">
                                    <div class="col-md-6">
                                        @if($courseProgress['first_tracking_date'])
                                        <small class="text-muted">
                                            <i class="fas fa-play-circle mr-1"></i>
                                            <strong>{{ __('Started:') }}</strong> {{ $courseProgress['first_tracking_date']->format('M d, Y h:i A') }}
                                        </small>
                                        @endif
                                    </div>
                                    <div class="col-md-6">
                                        @if($courseProgress['last_completed_date'])
                                        <small class="text-muted">
                                            <i class="fas fa-check-circle mr-1"></i>
                                            <strong>{{ __('Last Completed:') }}</strong> {{ $courseProgress['last_completed_date']->format('M d, Y h:i A') }}
                                        </small>
                                        @endif
                                    </div>
                                </div>
                                @endif
                            </div>
                        </div>
                        @endif

                        <hr>

                        <!-- Refund Information -->
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-money-bill-wave mr-2"></i>{{ __('Refund Information') }}
                                </h6>
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <td width="180"><strong>{{ __('Refund Amount:') }}</strong></td>
                                        <td><span class="h5 text-success font-weight-bold">{{ $currencySymbol }}{{ number_format($refund->refund_amount, 2) }}</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Purchase Date:') }}</strong></td>
                                        <td>{{ $refund->purchase_date ? $refund->purchase_date->format('M d, Y h:i A') : 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Request Date:') }}</strong></td>
                                        <td>{{ $refund->request_date ? $refund->request_date->format('M d, Y h:i A') : $refund->created_at->format('M d, Y h:i A') }}</td>
                                    </tr>
                                    @if($refund->processed_at)
                                    <tr>
                                        <td><strong>{{ __('Processed Date:') }}</strong></td>
                                        <td>{{ $refund->processed_at->format('M d, Y h:i A') }}</td>
                                    </tr>
                                    @endif
                                    @if($refund->processedByUser)
                                    <tr>
                                        <td><strong>{{ __('Processed By:') }}</strong></td>
                                        <td>{{ $refund->processedByUser->name }}</td>
                                    </tr>
                                    @endif
                                </table>
                            </div>
                        </div>

                        <!-- Transaction Information -->
                        @if($refund->transaction)
                        <hr>
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-receipt mr-2"></i>{{ __('Transaction Information') }}
                                </h6>
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <td width="180"><strong>{{ __('Transaction ID:') }}</strong></td>
                                        <td>{{ $refund->transaction->id }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Amount:') }}</strong></td>
                                        <td>{{ $currencySymbol }}{{ number_format($refund->transaction->amount, 2) }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Payment Method:') }}</strong></td>
                                        <td>{{ ucfirst($refund->transaction->payment_method ?? 'N/A') }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Status:') }}</strong></td>
                                        <td>
                                            <span class="badge badge-{{ $refund->transaction->status == 'completed' ? 'success' : 'warning' }}">
                                                {{ ucfirst($refund->transaction->status) }}
                                            </span>
                                        </td>
                                    </tr>
                                    @if($refund->transaction->order)
                                    <tr>
                                        <td><strong>{{ __('Order Number:') }}</strong></td>
                                        <td>{{ $refund->transaction->order->order_number ?? 'N/A' }}</td>
                                    </tr>
                                    @endif
                                </table>
                            </div>
                        </div>
                        @endif

                        <!-- Reason -->
                        @if($refund->reason)
                        <hr>
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-comment-alt mr-2"></i>{{ __("User's Reason for Refund") }}
                                </h6>
                                <div class="border rounded p-3 bg-light">
                                    {{ $refund->reason }}
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- User Media -->
                        @if($refund->user_media)
                        <hr>
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-paperclip mr-2"></i>{{ __("User's Media/Attachment") }}
                                </h6>
                                @php
                                    $mediaUrl = \App\Services\FileService::getFileUrl($refund->user_media);
                                    $extension = strtolower(pathinfo($refund->user_media, PATHINFO_EXTENSION));
                                    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                                    $videoExtensions = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm'];
                                @endphp
                                
                                @if(in_array($extension, $imageExtensions))
                                    <div class="border rounded p-3 bg-light">
                                        <img src="{{ $mediaUrl }}" alt="User Media" class="img-fluid" style="max-height: 500px; border-radius: 5px;">
                                        <div class="mt-2">
                                            <a href="{{ $mediaUrl }}" target="_blank" class="btn btn-sm btn-primary">
                                                <i class="fas fa-external-link-alt mr-1"></i>{{ __('View Full Size') }}
                                            </a>
                                            <a href="{{ $mediaUrl }}" download class="btn btn-sm btn-secondary">
                                                <i class="fas fa-download mr-1"></i>{{ __('Download') }}
                                            </a>
                                        </div>
                                    </div>
                                @elseif(in_array($extension, $videoExtensions))
                                    <div class="border rounded p-3 bg-light">
                                        <video controls class="w-100" style="max-height: 500px; border-radius: 5px;">
                                            <source src="{{ $mediaUrl }}" type="video/{{ $extension }}">
                                            {{ __('Your browser does not support the video tag.') }}
                                        </video>
                                        <div class="mt-2">
                                            <a href="{{ $mediaUrl }}" download class="btn btn-sm btn-secondary">
                                                <i class="fas fa-download mr-1"></i>{{ __('Download Video') }}
                                            </a>
                                        </div>
                                    </div>
                                @else
                                    <div class="border rounded p-3 bg-light">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-file fa-3x text-primary mr-3"></i>
                                            <div>
                                                <p class="mb-1"><strong>{{ __('File Attachment') }}</strong></p>
                                                <p class="mb-0 text-muted">{{ __('File type:') }} {{ strtoupper($extension) }}</p>
                                            </div>
                                        </div>
                                        <div class="mt-3">
                                            <a href="{{ $mediaUrl }}" target="_blank" class="btn btn-sm btn-primary">
                                                <i class="fas fa-external-link-alt mr-1"></i>{{ __('View File') }}
                                            </a>
                                            <a href="{{ $mediaUrl }}" download class="btn btn-sm btn-secondary">
                                                <i class="fas fa-download mr-1"></i>{{ __('Download') }}
                                            </a>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                        @endif

                        <!-- Admin Notes -->
                        @if($refund->admin_notes)
                        <hr>
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-sticky-note mr-2"></i>{{ __('Admin Notes') }}
                                </h6>
                                <div class="border rounded p-3 bg-light">
                                    {{ $refund->admin_notes }}
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- Admin Receipt -->
                        @if($refund->admin_receipt)
                        <hr>
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="font-weight-bold text-primary">
                                    <i class="fas fa-receipt mr-2"></i>{{ __('Admin Receipt') }}
                                </h6>
                                @php
                                    $receiptUrl = \App\Services\FileService::getFileUrl($refund->admin_receipt);
                                    $receiptExtension = strtolower(pathinfo($refund->admin_receipt, PATHINFO_EXTENSION));
                                    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                                @endphp
                                
                                @if(in_array($receiptExtension, $imageExtensions))
                                    <div class="border rounded p-3 bg-light">
                                        <img src="{{ $receiptUrl }}" alt="Admin Receipt" class="img-fluid" style="max-height: 500px; border-radius: 5px;">
                                        <div class="mt-2">
                                            <a href="{{ $receiptUrl }}" target="_blank" class="btn btn-sm btn-primary">
                                                <i class="fas fa-external-link-alt mr-1"></i>{{ __('View Full Size') }}
                                            </a>
                                            <a href="{{ $receiptUrl }}" download class="btn btn-sm btn-secondary">
                                                <i class="fas fa-download mr-1"></i>{{ __('Download') }}
                                            </a>
                                        </div>
                                    </div>
                                @else
                                    <div class="border rounded p-3 bg-light">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-file-pdf fa-3x text-danger mr-3"></i>
                                            <div>
                                                <p class="mb-1"><strong>{{ __('Receipt Document') }}</strong></p>
                                                <p class="mb-0 text-muted">{{ __('File type:') }} {{ strtoupper($receiptExtension) }}</p>
                                            </div>
                                        </div>
                                        <div class="mt-3">
                                            <a href="{{ $receiptUrl }}" target="_blank" class="btn btn-sm btn-primary">
                                                <i class="fas fa-external-link-alt mr-1"></i>{{ __('View Receipt') }}
                                            </a>
                                            <a href="{{ $receiptUrl }}" download class="btn btn-sm btn-secondary">
                                                <i class="fas fa-download mr-1"></i>{{ __('Download') }}
                                            </a>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Action Panel -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-cogs mr-2"></i>{{ __('Actions') }}
                        </h5>
                        
                        @if($refund->status == 'pending')
                        <form method="POST" action="{{ route('admin.refunds.process', $refund->id) }}" class="create-form" data-success-function="formSuccessFunction" enctype="multipart/form-data"
                              data-approve-title="{{ __('Approve Refund Request?') }}"
                              data-reject-title="{{ __('Reject Refund Request?') }}"
                              data-approve-text="{{ __('Are you sure you want to approve this refund? The amount will be credited to user wallet and course access will be removed.') }}"
                              data-reject-text="{{ __('Are you sure you want to reject this refund request?') }}"
                              data-yes-approve="{{ __('Yes, Approve') }}"
                              data-yes-reject="{{ __('Yes, Reject') }}"
                              data-cancel="{{ __('Cancel') }}"
>
                            @csrf
                            <div class="form-group">
                                <label for="admin_notes">{{ __('Admin Notes (Optional)') }}</label>
                                <textarea name="admin_notes" id="admin_notes" class="form-control" rows="4" placeholder="{{ __('Add any notes about this refund decision...') }}"></textarea>
                            </div>


                            <input type="hidden" name="action" id="refund_action" value="">

                            <div class="row">
                                <div class="col-6">
                                    <button type="button" class="btn btn-success btn-block" onclick="submitRefundAction('approve')">
                                        <i class="fas fa-check"></i> {{ __('Approve') }}
                                    </button>
                                </div>
                                <div class="col-6">
                                    <button type="button" class="btn btn-danger btn-block" onclick="submitRefundAction('reject')">
                                        <i class="fas fa-times"></i> {{ __('Reject') }}
                                    </button>
                                </div>
                            </div>
                        </form>
                        @else
                        <div class="alert alert-{{ $refund->status == 'approved' ? 'success' : 'danger' }}">
                            <i class="fas fa-info-circle mr-2"></i>
                            {{ __('This refund request has already been') }} {{ $refund->status == 'approved' ? __('approved') : __('rejected') }}.
                        </div>
                        
                        @if($refund->status == 'approved')
                        <div class="text-success mt-3">
                            <i class="fas fa-check-circle mr-2"></i>
                            <small>{{ __('Refund of') }} {{ $currencySymbol }}{{ number_format($refund->refund_amount, 2) }} {{ __("has been credited to user's wallet.") }}</small>
                        </div>
                        @endif
                        @endif
                    </div>
                </div>

                <!-- User's Refund History -->
                <div class="card mt-3">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-history mr-2"></i>{{ __("User's Refund History") }}
                        </h5>
                        @php
                            $userRefunds = App\Models\RefundRequest::where('user_id', $refund->user_id)->get();
                            $totalRefunds = $userRefunds->count();
                            $approvedRefunds = $userRefunds->where('status', 'approved')->count();
                            $totalRefundAmount = $userRefunds->where('status', 'approved')->sum('refund_amount');
                        @endphp
                        <div class="row text-center">
                            <div class="col-4">
                                <h4 class="text-primary mb-0">{{ $totalRefunds }}</h4>
                                <small class="text-muted">{{ __('Total') }}</small>
                            </div>
                            <div class="col-4">
                                <h4 class="text-success mb-0">{{ $approvedRefunds }}</h4>
                                <small class="text-muted">{{ __('Approved') }}</small>
                            </div>
                            <div class="col-4">
                                <h4 class="text-info mb-0">{{ $currencySymbol }}{{ number_format($totalRefundAmount, 2) }}</h4>
                                <small class="text-muted">{{ __('Refunded') }}</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script>
    function formSuccessFunction(response) {
        setTimeout(() => {
            window.location.reload();
        }, 1500);
    }
</script>
@endsection

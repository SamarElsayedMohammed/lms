@extends('layouts.app')

@section('title')
    {{ __('Orders Management') }}
@endsection

@push('style')
    <link rel="stylesheet" href="{{ asset('library/datatables/media/css/jquery.dataTables.min.css') }}">
    <link rel="stylesheet" href="{{ asset('library/bootstrap-daterangepicker/daterangepicker.css') }}">
    <link rel="stylesheet" href="{{ asset('library/select2/dist/css/select2.min.css') }}">
@endpush

@section('main')
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-shopping-cart"></i> {{ __('Orders Management') }}</h1>
            <div class="section-header-button">
                <button class="btn btn-primary" onclick="refreshData()">
                    <i class="fas fa-sync-alt"></i> {{ __('Refresh') }}
                </button>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-primary">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Orders') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ number_format($stats['total_orders']) }}
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
                            <h4>{{ __('Pending Orders') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ number_format($stats['pending_orders']) }}
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
                            <h4>{{ __('Completed Orders') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ number_format($stats['completed_orders']) }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-info">
                        <i class="fas fa-rupee-sign"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Revenue') }}</h4>
                        </div>
                        <div class="card-body">
                            ₹{{ number_format($stats['total_revenue']) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-filter"></i> {{ __('Filters') }}</h4>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('admin.orders.index') }}">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>{{ __('Status') }}</label>
                                <select name="status" class="form-control">
                                    <option value="">{{ __('All Status') }}</option>
                                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                                    <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>{{ __('Completed') }}</option>
                                    <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>{{ __('Cancelled') }}</option>
                                    <option value="failed" {{ request('status') == 'failed' ? 'selected' : '' }}>{{ __('Failed') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>{{ __('Payment Method') }}</label>
                                <select name="payment_method" class="form-control">
                                    <option value="">{{ __('All Methods') }}</option>
                                    <option value="stripe" {{ request('payment_method') == 'stripe' ? 'selected' : '' }}>{{ __('Stripe') }}</option>
                                    <option value="razorpay" {{ request('payment_method') == 'razorpay' ? 'selected' : '' }}>{{ __('Razorpay') }}</option>
                                    <option value="flutterwave" {{ request('payment_method') == 'flutterwave' ? 'selected' : '' }}>{{ __('Flutterwave') }}</option>
                                    <option value="wallet" {{ request('payment_method') == 'wallet' ? 'selected' : '' }}>{{ __('Wallet') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>{{ __('Date From') }}</label>
                                <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>{{ __('Date To') }}</label>
                                <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ __('Search') }}</label>
                                <input type="text" name="search" class="form-control" placeholder="{{ __('Search by order number, customer name or email') }}" value="{{ request('search') }}">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i> {{ __('Apply Filters') }}
                                    </button>
                                    <a href="{{ route('admin.orders.index') }}" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> {{ __('Clear') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-list"></i> {{ __('Orders List') }}</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped" id="ordersTable">
                        <thead>
                            <tr>
                                <th>{{ __('Order ID') }}</th>
                                <th>{{ __('Customer') }}</th>
                                <th>{{ __('Amount') }}</th>
                                <th>{{ __('Payment Method') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($orders as $order)
                                <tr>
                                    <td>
                                        <strong>#{{ $order->order_number ?? $order->id }}</strong>
                                    </td>
                                    <td>
                                        <div>
                                            <strong>{{ $order->user->name ?? 'N/A' }}</strong><br>
                                            <small class="text-muted">{{ $order->user->email ?? 'N/A' }}</small>
                                        </div>
                                    </td>
                                    <td>
                                        <strong>₹{{ number_format($order->final_price) }}</strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">{{ ucfirst($order->payment_method) }}</span>
                                    </td>
                                    <td>
                                        @switch($order->status)
                                            @case('pending')
                                                <span class="badge badge-warning">{{ __('Pending') }}</span>
                                                @break
                                            @case('completed')
                                                <span class="badge badge-success">{{ __('Completed') }}</span>
                                                @break
                                            @case('cancelled')
                                                <span class="badge badge-danger">{{ __('Cancelled') }}</span>
                                                @break
                                            @case('failed')
                                                <span class="badge badge-dark">{{ __('Failed') }}</span>
                                                @break
                                            @default
                                                <span class="badge badge-secondary">{{ ucfirst($order->status) }}</span>
                                        @endswitch
                                    </td>
                                    <td>
                                        {{ $order->created_at->format('d M Y, H:i') }}
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="{{ route('admin.orders.show', $order->id) }}" class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-secondary dropdown-toggle" data-toggle="dropdown">
                                                    <i class="fas fa-cog"></i>
                                                </button>
                                                <div class="dropdown-menu">
                                                    <a class="dropdown-item" href="#" onclick="return updateStatus({{ $order->id }}, 'pending');">
                                                        <i class="fas fa-clock text-warning"></i> {{ __('Mark Pending') }}
                                                    </a>
                                                    <a class="dropdown-item" href="#" onclick="return updateStatus({{ $order->id }}, 'completed');">
                                                        <i class="fas fa-check text-success"></i> {{ __('Mark Completed') }}
                                                    </a>
                                                    <a class="dropdown-item" href="#" onclick="return updateStatus({{ $order->id }}, 'cancelled');">
                                                        <i class="fas fa-times text-danger"></i> {{ __('Mark Cancelled') }}
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                @if($orders->hasPages())
                <div class="d-flex justify-content-center mt-4">
                    <nav aria-label="Page navigation">
                        <ul class="pagination pagination-md mb-0">
                            {{-- Previous Page Link --}}
                            @if ($orders->onFirstPage())
                                <li class="page-item disabled">
                                    <span class="page-link" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; Previous</span>
                                    </span>
                                </li>
                            @else
                                <li class="page-item">
                                    <a class="page-link" href="{{ $orders->appends(request()->query())->previousPageUrl() }}" aria-label="Previous">
                                        <span aria-hidden="true">&laquo; Previous</span>
                                    </a>
                                </li>
                            @endif

                            {{-- Pagination Elements --}}
                            @php
                                $currentPage = $orders->currentPage();
                                $lastPage = $orders->lastPage();
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($lastPage, $currentPage + 2);
                                
                                // Show first page if not in range
                                if ($startPage > 1) {
                                    $showFirstPage = true;
                                } else {
                                    $showFirstPage = false;
                                }
                                
                                // Show last page if not in range
                                if ($endPage < $lastPage) {
                                    $showLastPage = true;
                                } else {
                                    $showLastPage = false;
                                }
                            @endphp
                            
                            {{-- First Page --}}
                            @if($showFirstPage)
                                <li class="page-item">
                                    <a class="page-link" href="{{ $orders->appends(request()->query())->url(1) }}">1</a>
                                </li>
                                @if($startPage > 2)
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                @endif
                            @endif
                            
                            {{-- Page Numbers --}}
                            @for ($page = $startPage; $page <= $endPage; $page++)
                                @if ($page == $currentPage)
                                    <li class="page-item active">
                                        <span class="page-link">{{ $page }}</span>
                                    </li>
                                @else
                                    <li class="page-item">
                                        <a class="page-link" href="{{ $orders->appends(request()->query())->url($page) }}">{{ $page }}</a>
                                    </li>
                                @endif
                            @endfor
                            
                            {{-- Last Page --}}
                            @if($showLastPage)
                                @if($endPage < $lastPage - 1)
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                @endif
                                <li class="page-item">
                                    <a class="page-link" href="{{ $orders->appends(request()->query())->url($lastPage) }}">{{ $lastPage }}</a>
                                </li>
                            @endif

                            {{-- Next Page Link --}}
                            @if ($orders->hasMorePages())
                                <li class="page-item">
                                    <a class="page-link" href="{{ $orders->appends(request()->query())->nextPageUrl() }}" aria-label="Next">
                                        <span aria-hidden="true">Next &raquo;</span>
                                    </a>
                                </li>
                            @else
                                <li class="page-item disabled">
                                    <span class="page-link" aria-label="Next">
                                        <span aria-hidden="true">Next &raquo;</span>
                                    </span>
                                </li>
                            @endif
                        </ul>
                    </nav>
                </div>
                <div class="d-flex justify-content-center mt-2">
                    <p class="text-muted small">
                        Showing {{ $orders->firstItem() }} to {{ $orders->lastItem() }} of {{ $orders->total() }} results
                    </p>
                </div>
                @endif
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('library/datatables/media/js/jquery.dataTables.min.js') }}"></script>
    <script>
        $(document).ready(function() {
            $('#ordersTable').DataTable({
                "paging": false,
                "searching": false,
                "ordering": true,
                "info": false
            });
        });

        function updateStatus(orderId, status) {
            if (confirm('Are you sure you want to update the order status to "' + status + '"?')) {
                $.ajax({
                    url: '{{ route("admin.orders.update-status", ":id") }}'.replace(':id', orderId),
                    type: 'PATCH',
                    data: {
                        status: status,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        if (response && response.message) {
                            alert(response.message);
                        }
                        location.reload();
                    },
                    error: function(xhr) {
                        var errorMessage = 'Error updating order status';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMessage = xhr.responseJSON.message;
                        } else if (xhr.responseText) {
                            try {
                                var errorData = JSON.parse(xhr.responseText);
                                if (errorData.message) {
                                    errorMessage = errorData.message;
                                }
                            } catch(e) {
                                // If not JSON, use default message
                            }
                        }
                        alert(errorMessage);
                    }
                });
            }
            return false;
        }

        function refreshData() {
            location.reload();
        }
    </script>
@endpush

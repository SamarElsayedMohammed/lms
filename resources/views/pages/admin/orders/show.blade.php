@extends('layouts.app')

@section('title')
    {{ __('Order Details') }} #{{ $order->order_number ?? $order->id }}
@endsection

@section('main')
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-shopping-cart"></i> {{ __('Order Details') }} #{{ $order->order_number ?? $order->id }}</h1>
            <div class="section-header-button">
                <a href="{{ route('admin.orders.index') }}" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> {{ __('Back to Orders') }}
                </a>
            </div>
        </div>

        <div class="row">
            <!-- Order Information -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-info-circle"></i> {{ __('Order Information') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-borderless">
                                    <tr>
                                        <td><strong>{{ __('Order Number') }}:</strong></td>
                                        <td>#{{ $order->order_number ?? $order->id }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Order Date') }}:</strong></td>
                                        <td>{{ $order->created_at->format('d M Y, H:i') }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Status') }}:</strong></td>
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
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Payment Method') }}:</strong></td>
                                        <td><span class="badge badge-info">{{ ucfirst($order->payment_method) }}</span></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-borderless">
                                    <tr>
                                        <td><strong>{{ __('Subtotal') }}:</strong></td>
                                        <td>₹{{ number_format($order->subtotal) }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Tax') }}:</strong></td>
                                        <td>₹{{ number_format($order->tax_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Discount') }}:</strong></td>
                                        <td>₹{{ number_format($order->discount_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Total Amount') }}:</strong></td>
                                        <td><strong>₹{{ number_format($order->final_price) }}</strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Information -->
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-user"></i> {{ __('Customer Information') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-borderless">
                                    <tr>
                                        <td><strong>{{ __('Name') }}:</strong></td>
                                        <td>{{ $order->user->name ?? 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Email') }}:</strong></td>
                                        <td>{{ $order->user->email ?? 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Phone') }}:</strong></td>
                                        <td>{{ $order->user->phone ?? 'N/A' }}</td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-borderless">
                                    <tr>
                                        <td><strong>{{ __('Registration Date') }}:</strong></td>
                                        <td>{{ $order->user->created_at->format('d M Y') ?? 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>{{ __('Total Orders') }}:</strong></td>
                                        <td>{{ $order->user->orders->count() ?? 0 }}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Courses -->
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-book"></i> {{ __('Ordered Courses') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>{{ __('Course') }}</th>
                                        <th>{{ __('Instructor') }}</th>
                                        <th>{{ __('Price') }}</th>
                                        <th>{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($order->orderCourses as $orderCourse)
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong>{{ $orderCourse->course->title ?? 'N/A' }}</strong><br>
                                                    <small class="text-muted">{{ $orderCourse->course->description ?? '' }}</small>
                                                </div>
                                            </td>
                                            <td>
                                                {{ $orderCourse->course->user->name ?? 'N/A' }}
                                            </td>
                                            <td>
                                                ₹{{ number_format($orderCourse->price) }}
                                            </td>
                                            <td>
                                                <span class="badge badge-success">{{ __('Enrolled') }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Promo Code -->
                @if($order->promoCode)
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-tag"></i> {{ __('Applied Promo Code') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>{{ __('Code') }}</th>
                                        <th>{{ __('Type') }}</th>
                                        <th>{{ __('Value') }}</th>
                                        <th>{{ __('Discount') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>{{ $order->promoCode->code ?? 'N/A' }}</strong></td>
                                        <td>{{ ucfirst($order->promoCode->type ?? 'N/A') }}</td>
                                        <td>{{ $order->promoCode->value ?? 'N/A' }}</td>
                                        <td>₹{{ number_format($order->discount_amount ?? 0, 2) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                @endif
            </div>

            <!-- Order Actions -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-cogs"></i> {{ __('Order Actions') }}</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.orders.update-status', $order->id) }}">
                            @csrf
                            @method('PATCH')
                            <div class="form-group">
                                <label for="status">{{ __('Update Status') }}</label>
                                <select name="status" id="status" class="form-control">
                                    <option value="pending" {{ $order->status == 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                                    <option value="completed" {{ $order->status == 'completed' ? 'selected' : '' }}>{{ __('Completed') }}</option>
                                    <option value="cancelled" {{ $order->status == 'cancelled' ? 'selected' : '' }}>{{ __('Cancelled') }}</option>
                                    <option value="failed" {{ $order->status == 'failed' ? 'selected' : '' }}>{{ __('Failed') }}</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-save"></i> {{ __('Update Status') }}
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Payment Information -->
                @if($order->paymentTransaction)
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-credit-card"></i> {{ __('Payment Information') }}</h4>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <td><strong>{{ __('Transaction ID') }}:</strong></td>
                                <td>{{ $order->paymentTransaction->transaction_id ?? 'N/A' }}</td>
                            </tr>
                            <tr>
                                <td><strong>{{ __('Payment Status') }}:</strong></td>
                                <td>
                                    <span class="badge badge-success">{{ ucfirst($order->paymentTransaction->status ?? 'N/A') }}</span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>{{ __('Payment Date') }}:</strong></td>
                                <td>{{ $order->paymentTransaction->created_at->format('d M Y, H:i') ?? 'N/A' }}</td>
                            </tr>
                        </table>
                    </div>
                </div>
                @endif

                <!-- Order Summary -->
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-calculator"></i> {{ __('Order Summary') }}</h4>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <td>{{ __('Subtotal') }}:</td>
                                <td class="text-right">₹{{ number_format($order->subtotal) }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('Tax') }}:</td>
                                <td class="text-right">₹{{ number_format($order->tax_amount) }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('Discount') }}:</td>
                                <td class="text-right text-success">-₹{{ number_format($order->discount_amount) }}</td>
                            </tr>
                            <tr class="border-top">
                                <td><strong>{{ __('Total') }}:</strong></td>
                                <td class="text-right"><strong>₹{{ number_format($order->final_price) }}</strong></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

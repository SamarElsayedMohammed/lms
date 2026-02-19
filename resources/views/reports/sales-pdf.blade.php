<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title> {{ __('Sales Report') }} </title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            font-size: 12px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #333;
        }
        .filters {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .filters h3 {
            margin-top: 0;
            color: #666;
        }
        .filter-item {
            display: inline-block;
            margin-right: 20px;
            margin-bottom: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #333;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .status {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        .status.completed {
            background-color: #d4edda;
            color: #155724;
        }
        .status.pending {
            background-color: #fff3cd;
            color: #856404;
        }
        .status.cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }
        .status.failed {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .summary {
            background: #e9ecef;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .summary-item {
            display: inline-block;
            margin-right: 30px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1> {{ __('Sales Report') }} </h1>
        <p>Generated on: {{ $generated_at }}</p>
    </div>
    @if(!empty($filters))
    <div class="filters">
        <h3>{{ __('Applied Filters:') }}</h3>
        @if(isset($filters['date_from']) || isset($filters['date_to']))
        <div class="filter-item">
            <strong>{{ __('Date Range:') }}</strong> 
            {{ $filters['date_from'] ?? 'Start' }} to {{ $filters['date_to'] ?? 'End' }}
        </div>
        @endif
        @if(isset($filters['status']) && $filters['status'])
        <div class="filter-item">
            <strong>{{ __('Status:') }}</strong> {{ ucfirst($filters['status']) }}
        </div>
        @endif
        @if(isset($filters['payment_method']) && $filters['payment_method'])
        <div class="filter-item">
            <strong>{{ __('Payment Method:') }}</strong> {{ ucfirst($filters['payment_method']) }}
        </div>
        @endif
        @if(isset($filters['course_id']) && $filters['course_id'])
        <div class="filter-item">
            <strong>{{ __('Course:') }}</strong> {{ $filters['course_name'] ?? $filters['course_id'] }}
        </div>
        @endif
        @if(isset($filters['instructor_id']) && $filters['instructor_id'])
        <div class="filter-item">
            <strong>{{ __('Instructor:') }}</strong> {{ $filters['instructor_name'] ?? $filters['instructor_id'] }}
        </div>
        @endif
        @if(isset($filters['category_id']) && $filters['category_id'])
        <div class="filter-item">
            <strong>{{ __('Category:') }}</strong> {{ $filters['category_name'] ?? $filters['category_id'] }}
        </div>
        @endif
    </div>
    @endif <div class="summary">
        <div class="summary-item">Total Orders: {{ $orders->count() }}</div>
        <div class="summary-item">Total Revenue: {{ $currency_symbol ?? '₹' }}{{ number_format($orders->sum('final_price') ?? 0, 2) }}</div>
        <div class="summary-item">Average Order: {{ $currency_symbol ?? '₹' }}{{ number_format($orders->avg('final_price') ?? 0, 2) }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th> {{ __('Order ID') }} </th>
                <th> {{ __('Date') }} </th>
                <th> {{ __('Customer') }} </th>
                <th> {{ __('Course') }} </th>
                <th> {{ __('Amount') }} </th>
                <th> {{ __('Payment Method') }} </th>
                <th> {{ __('Status') }} </th>
            </tr>
        </thead>
        <tbody> @forelse($orders as $order)
                @foreach($order->orderCourses as $orderCourse) <tr>
                        <td>#{{ $order->id }}</td>
                        <td>{{ $order->created_at->format('d M Y') }}</td>
                        <td>{{ $order->user->name ?? 'N/A' }}</td>
                        <td>{{ $orderCourse->course->title ?? 'N/A' }}</td>
                        <td>{{ $currency_symbol ?? '₹' }}{{ number_format($order->final_price, 2) }}</td>
                        <td>{{ ucfirst($order->payment_method ?? 'N/A') }}</td>
                        <td>
                            <span class="status {{ strtolower($order->status) }}">
                                {{ ucfirst($order->status ?? 'N/A') }}
                            </span>
                        </td>
                    </tr> @endforeach
            @empty <tr>
                    <td colspan="7" style="text-align: center; color: #666; font-style: italic;"> {{ __('No sales data available for the selected criteria') }} </td>
                </tr> @endforelse </tbody>
    </table>

    <div class="footer">
        <p> {{ __('This report was generated automatically from the Learning Management System') }} </p>
        <p>Report contains {{ $orders->count() }} orders with total revenue of {{ $currency_symbol ?? '₹' }}{{ number_format($orders->sum('final_price') ?? 0, 2) }}</p>
    </div>
</body>
</html>

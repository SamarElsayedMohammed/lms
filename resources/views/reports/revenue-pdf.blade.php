<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title> {{ __('Revenue Report') }} </title>
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
        .summary {
            background: #e9ecef;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .summary-item {
            display: inline-block;
            margin-right: 30px;
        }
        .text-right {
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1> {{ __('Revenue Report') }} </h1>
        <p> {{ __('Generated on') }}: {{ date('d M Y H:i:s') }} </p>
    </div>

    @if(!empty($filters))
    <div class="filters">
        <h3> {{ __('Applied Filters') }} </h3>
        @if(isset($filters['date_from']) || isset($filters['date_to']))
            <div class="filter-item">
                <strong> {{ __('Date Range') }}:</strong> 
                {{ isset($filters['date_from']) ? date('d M Y', strtotime($filters['date_from'])) : 'N/A' }} - 
                {{ isset($filters['date_to']) ? date('d M Y', strtotime($filters['date_to'])) : 'N/A' }}
            </div>
        @endif
        @if(isset($filters['course_name']))
            <div class="filter-item">
                <strong> {{ __('Course') }}:</strong> {{ $filters['course_name'] }}
            </div>
        @endif
        @if(isset($filters['instructor_name']))
            <div class="filter-item">
                <strong> {{ __('Instructor') }}:</strong> {{ $filters['instructor_name'] }}
            </div>
        @endif
        @if(isset($filters['category_name']))
            <div class="filter-item">
                <strong> {{ __('Category') }}:</strong> {{ $filters['category_name'] }}
            </div>
        @endif
        @if(isset($filters['payment_method']))
            <div class="filter-item">
                <strong> {{ __('Payment Method') }}:</strong> {{ ucfirst($filters['payment_method']) }}
            </div>
        @endif
    </div>
    @endif

    <div class="summary">
        <div class="summary-item">
            <strong> {{ __('Total Orders') }}:</strong> {{ $orders->count() }}
        </div>
        <div class="summary-item">
            <strong> {{ __('Total Revenue') }}:</strong> {{ $currency_symbol }}{{ number_format($orders->sum('final_price'), 2) }}
        </div>
        <div class="summary-item">
            <strong> {{ __('Average Order Value') }}:</strong> 
            {{ $currency_symbol }}{{ $orders->count() > 0 ? number_format($orders->sum('final_price') / $orders->count(), 2) : '0.00' }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th> {{ __('Order ID') }} </th>
                <th> {{ __('Date') }} </th>
                <th> {{ __('Customer Name') }} </th>
                <th> {{ __('Customer Email') }} </th>
                <th> {{ __('Course Title') }} </th>
                <th> {{ __('Instructor') }} </th>
                <th> {{ __('Category') }} </th>
                <th class="text-right"> {{ __('Course Price') }} </th>
                <th class="text-right"> {{ __('Order Total') }} </th>
                <th> {{ __('Payment Method') }} </th>
                <th> {{ __('Status') }} </th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $order)
                @foreach($order->orderCourses as $orderCourse)
                    <tr>
                        <td>#{{ $order->id }}</td>
                        <td>{{ $order->created_at->format('d M Y') }}</td>
                        <td>{{ $order->user->name ?? 'N/A' }}</td>
                        <td>{{ $order->user->email ?? 'N/A' }}</td>
                        <td>{{ $orderCourse->course->title ?? 'N/A' }}</td>
                        <td>{{ $orderCourse->course->user->name ?? 'N/A' }}</td>
                        <td>{{ $orderCourse->course->category->name ?? 'N/A' }}</td>
                        <td class="text-right">{{ $currency_symbol }}{{ number_format($orderCourse->price ?? 0, 2) }}</td>
                        <td class="text-right">{{ $currency_symbol }}{{ number_format($order->final_price ?? 0, 2) }}</td>
                        <td>{{ ucfirst($order->payment_method ?? 'N/A') }}</td>
                        <td>{{ ucfirst($order->status ?? 'N/A') }}</td>
                    </tr>
                @endforeach
            @empty
                <tr>
                    <td colspan="11" style="text-align: center;"> {{ __('No revenue data found') }} </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>

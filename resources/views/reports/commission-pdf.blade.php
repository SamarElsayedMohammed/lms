<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title> {{ __('Commission Report') }} </title>
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
            font-size: 10px;
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
            font-size: 10px;
            font-weight: bold;
        }
        .status.paid {
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
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1> {{ __('Commission Report') }} </h1>
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
        @if(isset($filters['instructor_id']) && $filters['instructor_id'])
        <div class="filter-item">
            <strong>{{ __('Instructor:') }}</strong> {{ $filters['instructor_name'] ?? $filters['instructor_id'] }}
        </div>
        @endif
        @if(isset($filters['course_id']) && $filters['course_id'])
        <div class="filter-item">
            <strong>{{ __('Course:') }}</strong> {{ $filters['course_name'] ?? $filters['course_id'] }}
        </div>
        @endif
    </div>
    @endif <div class="summary">
        <div class="summary-item">Total Commissions: {{ $commissions->count() }}</div>
        <div class="summary-item">Total Admin Commission: {{ $currency_symbol ?? '₹' }}{{ number_format($commissions->sum('admin_commission_amount') ?? 0, 2) }}</div>
        <div class="summary-item">Total Instructor Commission: {{ $currency_symbol ?? '₹' }}{{ number_format($commissions->sum('instructor_commission_amount') ?? 0, 2) }}</div>
        <div class="summary-item">Paid: {{ $commissions->where('status', 'paid')->count() }}</div>
        <div class="summary-item">Pending: {{ $commissions->where('status', 'pending')->count() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('ID') }}</th>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Instructor') }}</th>
                <th>{{ __('Course') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Order') }}</th>
                <th>{{ __('Course Price') }}</th>
                <th>{{ __('Admin Rate') }}</th>
                <th>{{ __('Admin Amount') }}</th>
                <th>{{ __('Inst. Rate') }}</th>
                <th>{{ __('Inst. Amount') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Paid Date') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($commissions as $commission)
            @php
                $instructorType = 'N/A';
                if ($commission->instructor && $commission->instructor->instructor_details) {
                    $instructorType = ucfirst($commission->instructor->instructor_details->type ?? 'N/A');
                }
            @endphp
            <tr>
                <td>{{ $commission->id }}</td>
                <td>{{ $commission->created_at->format('d M Y') }}</td>
                <td>{{ $commission->instructor->name ?? 'N/A' }}</td>
                <td>{{ $commission->course->title ?? 'N/A' }}</td>
                <td>{{ $instructorType }}</td>
                <td>{{ $commission->order_id ?? 'N/A' }}</td>
                <td class="text-right">{{ $currency_symbol ?? '₹' }}{{ number_format($commission->discounted_price ?? $commission->course_price ?? 0, 2) }}</td>
                <td class="text-right">{{ number_format($commission->admin_commission_rate ?? 0, 2) }}%</td>
                <td class="text-right">{{ $currency_symbol ?? '₹' }}{{ number_format($commission->admin_commission_amount ?? 0, 2) }}</td>
                <td class="text-right">{{ number_format($commission->instructor_commission_rate ?? 0, 2) }}%</td>
                <td class="text-right">{{ $currency_symbol ?? '₹' }}{{ number_format($commission->instructor_commission_amount ?? 0, 2) }}</td>
                <td class="text-center">
                    <span class="status {{ strtolower($commission->status) }}">
                        {{ ucfirst($commission->status ?? 'N/A') }}
                    </span>
                </td>
                <td>{{ $commission->paid_at ? $commission->paid_at->format('d M Y') : 'N/A' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="13" style="text-align: center; color: #666; font-style: italic;">{{ __('No commission data available for the selected criteria') }}</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <p> {{ __('This report was generated automatically from the Learning Management System') }} </p>
        <p>Report contains {{ $commissions->count() }} commission records with total admin commission of {{ $currency_symbol ?? '₹' }}{{ number_format($commissions->sum('admin_commission_amount') ?? 0, 2) }} and total instructor commission of {{ $currency_symbol ?? '₹' }}{{ number_format($commissions->sum('instructor_commission_amount') ?? 0, 2) }}</p>
    </div>
</body>
</html>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Enrollment Report') }}</title>
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
            padding-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            color: #333;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .filters {
            margin-bottom: 20px;
            padding: 10px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }
        .filters h3 {
            margin: 0 0 10px 0;
            color: #333;
        }
        .filter-item {
            display: inline-block;
            margin-right: 20px;
            margin-bottom: 5px;
        }
        .filter-label {
            font-weight: bold;
            color: #555;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
            color: #333;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
            color: white;
        }
        .badge-success {
            background-color: #28a745;
        }
        .badge-danger {
            background-color: #dc3545;
        }
        .badge-info {
            background-color: #17a2b8;
        }
        .badge-warning {
            background-color: #ffc107;
            color: #333;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            color: #666;
            font-size: 10px;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ __('Enrollment Report') }}</h1>
        <p>Generated on: {{ $generated_at }}</p>
        <p>Total Enrollments: {{ $enrollments->count() }}</p>
    </div>
    @if(!empty($filters))
    <div class="filters">
        <h3>{{ __('Applied Filters:') }}</h3>
        @if(isset($filters['date_from']) && $filters['date_from'])
        <div class="filter-item">
            <span class="filter-label">{{ __('From:') }}</span> {{ $filters['date_from'] }}
        </div>
        @endif
        @if(isset($filters['date_to']) && $filters['date_to'])
        <div class="filter-item">
            <span class="filter-label">{{ __('To:') }}</span> {{ $filters['date_to'] }}
        </div>
        @endif
        @if(isset($filters['course_id']) && $filters['course_id'])
        <div class="filter-item">
            <span class="filter-label">{{ __('Course:') }}</span> {{ $filters['course_name'] ?? $filters['course_id'] }}
        </div>
        @endif
        @if(isset($filters['instructor_id']) && $filters['instructor_id'])
        <div class="filter-item">
            <span class="filter-label">{{ __('Instructor:') }}</span> {{ $filters['instructor_name'] ?? $filters['instructor_id'] }}
        </div>
        @endif
        @if(isset($filters['category_id']) && $filters['category_id'])
        <div class="filter-item">
            <span class="filter-label">{{ __('Category:') }}</span> {{ $filters['category_name'] ?? $filters['category_id'] }}
        </div>
        @endif
        @if(isset($filters['status']) && $filters['status'])
        <div class="filter-item">
            <span class="filter-label">{{ __('Status:') }}</span> {{ ucfirst($filters['status']) }}
        </div>
        @endif
    </div>
    @endif
    <table>
        <thead>
            <tr>
                <th>{{ __('Student Name') }}</th>
                <th>{{ __('Student Email') }}</th>
                <th>{{ __('Course Title') }}</th>
                <th>{{ __('Instructor') }}</th>
                <th>{{ __('Category') }}</th>
                <th>{{ __('Enrolled Date') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Progress') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($enrollments as $enrollment)
            <tr>
                <td>{{ $enrollment->user->name ?? 'N/A' }}</td>
                <td>{{ $enrollment->user->email ?? 'N/A' }}</td>
                <td>{{ $enrollment->course->title ?? 'N/A' }}</td>
                <td>{{ $enrollment->course->user->name ?? 'N/A' }}</td>
                <td>{{ $enrollment->course->category->name ?? 'N/A' }}</td>
                <td>{{ $enrollment->created_at->format('d M Y') }}</td>
                <td>
                    <span class="badge badge-{{ $enrollment->status === 'completed' ? 'success' : ($enrollment->status === 'in_progress' ? 'warning' : 'info') }}">
                        {{ ucfirst($enrollment->status ?? 'N/A') }}
                    </span>
                </td>
                <td>{{ number_format($enrollment->progress ?? 0, 2) }}%</td>
            </tr>
            @empty
            <tr>
                <td colspan="8" style="text-align: center; padding: 20px; color: #666;">{{ __('No enrollments found matching the selected criteria.') }}</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <p>{{ __('This report was generated automatically by the Learning Management System') }}</p>
        <p>© {{ date('Y') }} - All rights reserved</p>
    </div>
</body>
</html>

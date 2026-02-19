<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title> {{ __('Course Report') }} </title>
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
        .badge-primary {
            background-color: #007bff;
        }
        .badge-info {
            background-color: #17a2b8;
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
        <h1> {{ __('Course Report') }} </h1>
        <p>Generated on: {{ $generated_at }}</p>
        <p>Total Courses: {{ $courses->count() }}</p>
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
        @if(isset($filters['category_id']) && $filters['category_id'])
        <div class="filter-item">
            <span class="filter-label">{{ __('Category:') }}</span> {{ $filters['category_name'] ?? $filters['category_id'] }}
        </div>
        @endif
        @if(isset($filters['instructor_id']) && $filters['instructor_id'])
        <div class="filter-item">
            <span class="filter-label">{{ __('Instructor:') }}</span> {{ $filters['instructor_name'] ?? $filters['instructor_id'] }}
        </div>
        @endif
        @if(isset($filters['status']) && $filters['status'])
        <div class="filter-item">
            <span class="filter-label">{{ __('Status:') }}</span> {{ ucfirst($filters['status']) }}
        </div>
        @endif
        @if(isset($filters['course_type']) && $filters['course_type'])
        <div class="filter-item">
            <span class="filter-label">{{ __('Course Type:') }}</span> {{ ucfirst($filters['course_type']) }}
        </div>
        @endif
        @if(isset($filters['level']) && $filters['level'])
        <div class="filter-item">
            <span class="filter-label">{{ __('Level:') }}</span> {{ ucfirst($filters['level']) }}
        </div>
        @endif
    </div>
    @endif
    <table>
        <thead>
            <tr>
                <th> {{ __('Course Title') }} </th>
                <th> {{ __('Instructor') }} </th>
                <th> {{ __('Category') }} </th>
                <th> {{ __('Level') }} </th>
                <th> {{ __('Type') }} </th>
                <th> {{ __('Price') }} </th>
                <th> {{ __('Enrollments') }} </th>
                <th> {{ __('Revenue') }} </th>
                <th> {{ __('Rating') }} </th>
                <th> {{ __('Reviews') }} </th>
                <th> {{ __('Status') }} </th>
                <th> {{ __('Created Date') }} </th>
            </tr>
        </thead>
        <tbody> @forelse($courses as $course) <tr>
                <td>{{ $course->title }}</td>
                <td>{{ $course->user->name ?? 'N/A' }}</td>
                <td>{{ $course->category->name ?? 'N/A' }}</td>
                <td>{{ ucfirst($course->level ?? 'Not Specified') }}</td>
                <td>
                    <span class="badge badge-{{ $course->course_type === 'free' ? 'success' : 'primary' }}">
                        {{ ucfirst($course->course_type ?? 'N/A') }}
                    </span>
                </td>
                <td>
                    @if($course->price > 0)
                        {{ $currency_symbol ?? '₹' }}{{ number_format($course->price, 2) }}
                    @else
                        Free
                    @endif
                </td>
                <td>{{ $course->enrollments_count ?? 0 }}</td>
                <td>{{ $currency_symbol ?? '₹' }}{{ number_format($course->revenue ?? 0, 2) }}</td>
                <td>
                    @if(isset($course->average_rating) && $course->average_rating > 0)
                        {{ number_format($course->average_rating, 2) }}
                    @else
                        0.00
                    @endif
                </td>
                <td>{{ $course->reviews_count ?? 0 }}</td>
                <td>
                    <span class="badge badge-{{ $course->is_active ? 'success' : 'danger' }}">
                        {{ $course->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </td>
                <td>{{ $course->created_at->format('d M Y') }}</td>
            </tr> @empty <tr>
                <td colspan="12" style="text-align: center; padding: 20px; color: #666;"> {{ __('No courses found matching the selected criteria.') }} </td>
            </tr> @endforelse </tbody>
    </table>

    <div class="footer">
        <p> {{ __('This report was generated automatically by the Learning Management System') }} </p>
        <p>© {{ date('Y') }} - All rights reserved</p>
    </div>
</body>
</html>

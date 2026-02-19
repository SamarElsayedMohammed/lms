@extends('layouts.app')

@section('title')
    {{ __('Refund Requests') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a href="{{ route('settings.refund') }}" class="btn btn-primary">
            <i class="fas fa-cog"></i> {{ __('Refund Settings') }}
        </a>
    </div>
@endsection

@section('main')
    @php
        $currencyCode = \App\Services\HelperService::systemSettings('currency_code') ?? 'USD';
        $currencyData = \App\Services\HelperService::getCurrencyData($currencyCode);
        $currencySymbol = $currencyData['symbol'] ?? '$';
    @endphp
    <div class="content-wrapper">
        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-primary">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Requests') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ App\Models\RefundRequest::count() }}
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
                            <h4>{{ __('Pending') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ App\Models\RefundRequest::where('status', 'pending')->count() }}
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
                            <h4>{{ __('Approved') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ App\Models\RefundRequest::where('status', 'approved')->count() }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-danger">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Rejected') }}</h4>
                        </div>
                        <div class="card-body">
                            {{ App\Models\RefundRequest::where('status', 'rejected')->count() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Table List -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('Refund Requests') }}</h4>

                        <!-- Filter and Search -->
                        <form id="refundSearchForm" method="GET" action="{{ route('admin.refunds.index') }}" class="mb-4">
                            <div class="row align-items-end">
                                <div class="col-md-2">
                                    <label class="form-label text-muted small mb-1">{{ __('Status') }}</label>
                                    <select name="status" id="statusFilter" class="form-control">
                                        <option value="">{{ __('All Statuses') }}</option>
                                        <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                                        <option value="approved" {{ request('status') == 'approved' ? 'selected' : '' }}>{{ __('Approved') }}</option>
                                        <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>{{ __('Rejected') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-muted small mb-1">{{ __('Search') }}</label>
                                    <input type="text" name="search" id="searchInput" class="form-control" placeholder="{{ __('Search by user name, email, or course title...') }}" value="{{ request('search') }}">
                                </div>
                                <div class="col-md-3 col-sm-6">
                                    <label class="form-label text-muted small mb-1 d-block" style="visibility: hidden;">{{ __('Actions') }}</label>
                                    <div class="d-flex w-100 gap-2 flex-wrap">
                                        <button type="submit" class="btn btn-primary flex-fill" id="searchBtn">
                                            <i class="fas fa-search mr-2"></i>{{ __('Apply Filter') }}
                                        </button>
                                        <button type="button" class="btn btn-secondary flex-fill" id="resetBtn">
                                            <i class="fas fa-sync-alt mr-2"></i>{{ __('Clear') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <!-- Refund Requests Table -->
                        <div class="table-responsive" id="refundsTableContainer">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>{{ __('ID') }}</th>
                                        <th>{{ __('User') }}</th>
                                        <th>{{ __('Course') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Requested Date') }}</th>
                                        <th>{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="refundsTableBody">
                                    @forelse($refunds as $refund)
                                        <tr>
                                            <td>#{{ $refund->id }}</td>
                                            <td>
                                                <div>
                                                    <strong>{{ $refund->user->name ?? 'N/A' }}</strong><br>
                                                    <small class="text-muted">{{ $refund->user->email ?? 'N/A' }}</small>
                                                </div>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong>{{ \Illuminate\Support\Str::limit($refund->course->title ?? 'N/A', 30) }}</strong><br>
                                                    <small class="text-muted">ID: {{ $refund->course_id }}</small>
                                                </div>
                                            </td>
                                            <td>
                                                <strong class="text-success">{{ $currencySymbol }}{{ number_format($refund->refund_amount, 2) }}</strong>
                                            </td>
                                            <td>
                                                @if($refund->status == 'pending')
                                                    <span class="badge badge-warning">{{ __('Pending') }}</span>
                                                @elseif($refund->status == 'approved')
                                                    <span class="badge badge-success">{{ __('Approved') }}</span>
                                                @elseif($refund->status == 'rejected')
                                                    <span class="badge badge-danger">{{ __('Rejected') }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                {{ $refund->request_date ? $refund->request_date->format('M d, Y') : $refund->created_at->format('M d, Y') }}
                                                <br>
                                                <small class="text-muted">{{ $refund->request_date ? $refund->request_date->format('h:i A') : $refund->created_at->format('h:i A') }}</small>
                                            </td>
                                            <td>
                                                <a href="{{ route('admin.refunds.show', $refund->id) }}" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye"></i> {{ __('View') }}
                                                </a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center py-4">
                                                <div class="empty-state">
                                                    <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                                                    <h5>{{ __('No refund requests found') }}</h5>
                                                    <p class="text-muted">{{ __('There are no refund requests matching your criteria.') }}</p>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div id="paginationContainer">
                            @if($refunds->hasPages())
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <div id="pagination-info">
                                        Showing {{ $refunds->firstItem() ?? 0 }} to {{ $refunds->lastItem() ?? 0 }} of {{ $refunds->total() }} entries
                                    </div>
                                    <nav>
                                        <ul class="pagination mb-0">
                                            {{-- Previous Page Link --}}
                                            @if ($refunds->onFirstPage())
                                                <li class="page-item disabled">
                                                    <span class="page-link" aria-label="Previous">
                                                        <span aria-hidden="true">&laquo; Previous</span>
                                                    </span>
                                                </li>
                                            @else
                                                <li class="page-item">
                                                    <a class="page-link" href="{{ $refunds->appends(request()->query())->previousPageUrl() }}" aria-label="Previous">
                                                        <span aria-hidden="true">&laquo; Previous</span>
                                                    </a>
                                                </li>
                                            @endif

                                            {{-- Pagination Elements --}}
                                            @php
                                                $currentPage = $refunds->currentPage();
                                                $lastPage = $refunds->lastPage();
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
                                                    <a class="page-link" href="{{ $refunds->appends(request()->query())->url(1) }}">1</a>
                                                </li>
                                                @if($startPage > 2)
                                                    <li class="page-item disabled">
                                                        <span class="page-link">...</span>
                                                    </li>
                                                @endif
                                            @endif

                                            {{-- Page Numbers --}}
                                            @for ($i = $startPage; $i <= $endPage; $i++)
                                                <li class="page-item {{ $i == $currentPage ? 'active' : '' }}">
                                                    <a class="page-link" href="{{ $refunds->appends(request()->query())->url($i) }}">{{ $i }}</a>
                                                </li>
                                            @endfor

                                            {{-- Last Page --}}
                                            @if($showLastPage)
                                                @if($endPage < $lastPage - 1)
                                                    <li class="page-item disabled">
                                                        <span class="page-link">...</span>
                                                    </li>
                                                @endif
                                                <li class="page-item">
                                                    <a class="page-link" href="{{ $refunds->appends(request()->query())->url($lastPage) }}">{{ $lastPage }}</a>
                                                </li>
                                            @endif

                                            {{-- Next Page Link --}}
                                            @if ($refunds->hasMorePages())
                                                <li class="page-item">
                                                    <a class="page-link" href="{{ $refunds->appends(request()->query())->nextPageUrl() }}" aria-label="Next">
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
                            @else
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <div id="pagination-info">
                                        Showing {{ $refunds->count() }} of {{ $refunds->total() }} entries
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('style')
<style>
    #statusFilter,
    #searchInput {
        height: 31px;
    }
    /* Align search/reset horizontally like reports filters */
    #refundSearchForm .col-md-3 .d-flex {
        flex-direction: row;
        gap: 10px;
    }
    #refundSearchForm .col-md-3 .btn {
        flex: 1;
        height: 36px;
    }
    #refundSearchForm .col-md-3 .btn i {
        margin-right: 6px;
    }
    @media (max-width: 767.98px) {
        #refundSearchForm .col-md-3 .d-flex {
            flex-direction: column;
        }
    }
</style>
@endsection

@section('script')
<script>
    $(document).ready(function() {
        const form = $('#refundSearchForm');
        const tableBody = $('#refundsTableBody');
        const paginationContainer = $('#paginationContainer');
        const searchBtn = $('#searchBtn');
        const statusFilter = $('#statusFilter');
        const searchInput = $('#searchInput');

        // Handle form submission via AJAX
        form.on('submit', function(e) {
            e.preventDefault();
            performSearch();
        });

        // Handle reset button
        resetBtn.on('click', function() {
            statusFilter.val('');
            searchInput.val('');
            performSearch();
        });

        // Function to perform search
        function performSearch() {
            const formData = form.serialize();
            const url = form.attr('action') + '?' + formData;

            // Show loading state
            const originalHtml = searchBtn.html();
            searchBtn.prop('disabled', true)
                .css('opacity', '0.8')
                .html('<i class="fas fa-spinner fa-spin mr-2"></i>{{ __("Loading...") }}');

            $.ajax({
                url: url,
                method: 'GET',
                dataType: 'html',
                success: function(response) {
                    // Parse the response
                    const $response = $(response);
                    
                    // Update table body
                    const newTableBody = $response.find('#refundsTableBody').html();
                    tableBody.html(newTableBody || '<tr><td colspan="7" class="text-center py-4"><div class="empty-state"><i class="fas fa-file-alt fa-3x text-muted mb-3"></i><h5>{{ __("No refund requests found") }}</h5><p class="text-muted">{{ __("There are no refund requests matching your criteria.") }}</p></div></td></tr>');
                    
                    // Update pagination
                    const newPagination = $response.find('#paginationContainer').html();
                    paginationContainer.html(newPagination || '<div class="d-flex justify-content-between align-items-center mt-3"><div id="pagination-info">Showing 0 entries</div></div>');
                    
                    // Update URL without page reload
                    window.history.pushState({}, '', url);
                },
                error: function(xhr) {
                    console.error('Search failed:', xhr);
                    alert('{{ __("An error occurred while searching. Please try again.") }}');
                },
                complete: function() {
                    // Reset button state
                    searchBtn.prop('disabled', false)
                        .css('opacity', '1')
                        .html(originalHtml);
                }
            });
        }

        // Handle pagination links (if they exist)
        $(document).on('click', '#paginationContainer .pagination a.page-link', function(e) {
            e.preventDefault();
            const url = $(this).attr('href');
            if (url) {
                // Extract page number from URL
                const urlObj = new URL(url, window.location.origin);
                const page = urlObj.searchParams.get('page') || 1;
                
                // Update form with current filters and new page
                const formData = form.serialize();
                const params = new URLSearchParams(formData);
                params.set('page', page);
                
                // Build new URL with filters and page
                const newUrl = form.attr('action') + '?' + params.toString();
                
                // Update form action
                form.attr('action', url.split('?')[0]);
                
                // Perform search with new page
                performSearchWithUrl(newUrl);
            }
        });
        
        // Function to perform search with specific URL
        function performSearchWithUrl(searchUrl) {
            // Show loading state
            const originalHtml = searchBtn.html();
            searchBtn.prop('disabled', true)
                .css('opacity', '0.8')
                .html('<i class="fas fa-spinner fa-spin mr-2"></i>{{ __("Loading...") }}');

            $.ajax({
                url: searchUrl,
                method: 'GET',
                dataType: 'html',
                success: function(response) {
                    // Parse the response
                    const $response = $(response);
                    
                    // Update table body
                    const newTableBody = $response.find('#refundsTableBody').html();
                    tableBody.html(newTableBody || '<tr><td colspan="7" class="text-center py-4"><div class="empty-state"><i class="fas fa-file-alt fa-3x text-muted mb-3"></i><h5>{{ __("No refund requests found") }}</h5><p class="text-muted">{{ __("There are no refund requests matching your criteria.") }}</p></div></td></tr>');
                    
                    // Update pagination
                    const newPagination = $response.find('#paginationContainer').html();
                    paginationContainer.html(newPagination || '<div class="d-flex justify-content-between align-items-center mt-3"><div id="pagination-info">Showing 0 entries</div></div>');
                    
                    // Update URL without page reload
                    window.history.pushState({}, '', searchUrl);
                },
                error: function(xhr) {
                    console.error('Search failed:', xhr);
                    alert('{{ __("An error occurred while searching. Please try again.") }}');
                },
                complete: function() {
                    // Reset button state
                    searchBtn.prop('disabled', false)
                        .css('opacity', '1')
                        .html(originalHtml);
                }
            });
        }
    });
</script>
@endsection

@extends('layouts.app')

@section('title')
    {{ __('Wallet Management') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
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
        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-primary">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Transactions') }}</h4>
                        </div>
                        <div class="card-body">
                            @php
                                $userWalletCount = \App\Models\WalletHistory::where('entry_type', 'user')->count();
                            @endphp
                            {{ $userWalletCount }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-success">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Credits') }}</h4>
                        </div>
                        <div class="card-body">
                            @php
                                $userCredits = \App\Models\WalletHistory::where('type', 'credit')
                                    ->where('entry_type', 'user')
                                    ->sum('amount');
                            @endphp
                            {{ $currencySymbol }}{{ number_format($userCredits, 2) }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-danger">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Debits') }}</h4>
                        </div>
                        <div class="card-body">
                            @php
                                $userDebits = \App\Models\WalletHistory::where('type', 'debit')
                                    ->where('entry_type', 'user')
                                    ->sum('amount');
                            @endphp
                            {{ $currencySymbol }}{{ number_format($userDebits, 2) }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                <div class="card card-statistic-1">
                    <div class="card-icon bg-info">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="card-wrap">
                        <div class="card-header">
                            <h4>{{ __('Total Users') }}</h4>
                        </div>
                        <div class="card-body">
                            @php
                                $userCount = \App\Models\WalletHistory::where('entry_type', 'user')
                                    ->distinct('user_id')
                                    ->count('user_id');
                            @endphp
                            {{ $userCount }}
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
                        <h4 class="card-title">{{ __('Wallet History') }}</h4>

                        <!-- Filter and Search -->
                        <form id="walletSearchForm" method="GET" action="{{ route('admin.wallets.index') }}" class="mb-4">
                            <div class="row align-items-end g-3">
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label text-muted small mb-1">{{ __('Type') }}</label>
                                    <select name="type" id="typeFilter" class="form-control">
                                        <option value="">{{ __('All Types') }}</option>
                                        <option value="credit" {{ request('type') == 'credit' ? 'selected' : '' }}>{{ __('Credit') }}</option>
                                        <option value="debit" {{ request('type') == 'debit' ? 'selected' : '' }}>{{ __('Debit') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label text-muted small mb-1">{{ __('Transaction Type') }}</label>
                                    <select name="transaction_type" id="transactionTypeFilter" class="form-control">
                                        <option value="">{{ __('All') }}</option>
                                        <option value="refund" {{ request('transaction_type') == 'refund' ? 'selected' : '' }}>{{ __('Refund') }}</option>
                                        <option value="purchase" {{ request('transaction_type') == 'purchase' ? 'selected' : '' }}>{{ __('Purchase') }}</option>
                                        <option value="commission" {{ request('transaction_type') == 'commission' ? 'selected' : '' }}>{{ __('Commission') }}</option>
                                        <option value="withdrawal" {{ request('transaction_type') == 'withdrawal' ? 'selected' : '' }}>{{ __('Withdrawal') }}</option>
                                        <option value="adjustment" {{ request('transaction_type') == 'adjustment' ? 'selected' : '' }}>{{ __('Adjustment') }}</option>
                                        <option value="reward" {{ request('transaction_type') == 'reward' ? 'selected' : '' }}>{{ __('Reward') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label text-muted small mb-1">{{ __('Date From') }}</label>
                                    <input type="date" name="date_from" id="dateFrom" class="form-control" value="{{ request('date_from') }}">
                                </div>
                                <div class="col-md-2 col-sm-6">
                                    <label class="form-label text-muted small mb-1">{{ __('Date To') }}</label>
                                    <input type="date" name="date_to" id="dateTo" class="form-control" value="{{ request('date_to') }}">
                                </div>
                                <div class="col-md-3 col-sm-8">
                                    <label class="form-label text-muted small mb-1">{{ __('Search') }}</label>
                                    <input type="text" name="search" id="searchInput" class="form-control" placeholder="{{ __('Search by user name or email...') }}" value="{{ request('search') }}">
                                </div>
                                <div class="col-md-3 col-sm-4 d-flex align-items-end justify-content-end">
                                    <div class="d-flex w-100 gap-2">
                                        <button type="submit" class="btn btn-primary flex-fill" id="searchBtn">
                                            <i class="fas fa-search mr-2"></i>{{ __('Search') }}
                                        </button>
                                        <button type="button" class="btn btn-secondary flex-fill" id="resetBtn">
                                            <i class="fas fa-sync-alt mr-2"></i>{{ __('Reset') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <!-- Wallet History Table -->
                        <div class="table-responsive">
                            <table class="table table-striped" id="walletTable">
                                <thead>
                                    <tr>
                                        <th>{{ __('ID') }}</th>
                                        <th>{{ __('User') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('Type') }}</th>
                                        <th>{{ __('Transaction Type') }}</th>
                                        <th>{{ __('Entry Type') }}</th>
                                        <th>{{ __('Description') }}</th>
                                        <th>{{ __('Balance Before') }}</th>
                                        <th>{{ __('Balance After') }}</th>
                                        <th>{{ __('Date') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="walletTableBody">
                                    <!-- Data will be loaded via AJAX -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('style')
<style>
    #typeFilter,
    #transactionTypeFilter,
    #dateFrom,
    #dateTo,
    #searchInput {
        height: 38px;
    }
    @media (max-width: 767.98px) {
        #walletSearchForm .d-flex {
            flex-direction: column;
        }
        #walletSearchForm .d-flex .btn {
            width: 100%;
        }
    }
</style>
@endsection

@section('script')
<script>
    $(document).ready(function() {
        const form = $('#walletSearchForm');
        const tableBody = $('#walletTableBody');
        const searchBtn = $('#searchBtn');
        const resetBtn = $('#resetBtn');

        // Define loadWalletData function first
        const loadWalletData = function() {
            const formData = form.serialize();
            const url = '{{ route("admin.wallets.data") }}?' + formData;

            // Show loading state
            const originalHtml = searchBtn.html();
            searchBtn.prop('disabled', true)
                .html('<i class="fas fa-spinner fa-spin"></i>');

            tableBody.html('<tr><td colspan="10" class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> {{ __("Loading...") }}</td></tr>');

            $.ajax({
                url: url,
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    console.log('Response received:', response);
                    if (response.error === false && response.data) {
                        let html = '';
                        if (response.data.length > 0) {
                            response.data.forEach(function(row) {
                                const typeClass = row.type === 'Credit' ? 'text-success' : 'text-danger';
                                const typeIcon = row.type === 'Credit' ? 'fa-arrow-up' : 'fa-arrow-down';
                                
                                html += '<tr>';
                                html += '<td>#' + row.id + '</td>';
                                html += '<td><div><strong>' + row.user_name + '</strong><br><small class="text-muted">' + row.user_email + '</small></div></td>';
                                html += '<td><strong class="' + typeClass + '"><i class="fas ' + typeIcon + '"></i> {{ $currencySymbol }}' + row.amount + '</strong></td>';
                                html += '<td><span class="badge badge-' + (row.type === 'Credit' ? 'success' : 'danger') + '">' + row.type + '</span></td>';
                                html += '<td>' + row.transaction_type + '</td>';
                                const entryTypeBadge = row.entry_type === 'User' ? 'primary' : (row.entry_type === 'Instructor' ? 'warning' : 'info');
                                html += '<td><span class="badge badge-' + entryTypeBadge + '">' + row.entry_type + '</span></td>';
                                html += '<td>' + (row.description || '-') + '</td>';
                                html += '<td>{{ $currencySymbol }}' + row.balance_before + '</td>';
                                html += '<td><strong>{{ $currencySymbol }}' + row.balance_after + '</strong></td>';
                                html += '<td>' + row.created_at + '</td>';
                                html += '</tr>';
                            });
                        } else {
                            html = '<tr><td colspan="10" class="text-center py-4"><div class="empty-state"><i class="fas fa-wallet fa-3x text-muted mb-3"></i><h5>{{ __("No wallet transactions found") }}</h5><p class="text-muted">{{ __("There are no wallet transactions matching your criteria.") }}</p></div></td></tr>';
                        }
                        tableBody.html(html);
                    } else {
                        console.error('Invalid response format:', response);
                        tableBody.html('<tr><td colspan="10" class="text-center py-4 text-danger">{{ __("Error loading data. Please try again.") }}</td></tr>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', {xhr: xhr, status: status, error: error, responseText: xhr.responseText});
                    tableBody.html('<tr><td colspan="10" class="text-center py-4 text-danger">{{ __("An error occurred while loading data. Please try again.") }}<br><small>' + error + '</small></td></tr>');
                },
                complete: function() {
                    searchBtn.prop('disabled', false).html(originalHtml);
                }
            });
        };

        // Handle form submission
        form.on('submit', function(e) {
            e.preventDefault();
            loadWalletData();
        });

        // Handle reset button
        resetBtn.on('click', function() {
            $('#typeFilter').val('');
            $('#transactionTypeFilter').val('');
            $('#dateFrom').val('');
            $('#dateTo').val('');
            $('#searchInput').val('');
            loadWalletData();
        });

        // Load data on page load
        loadWalletData();
    });
</script>
@endsection

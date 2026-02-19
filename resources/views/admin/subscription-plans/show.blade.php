@extends('layouts.app')

@section('title')
    {{ __('Plan Details') }}: {{ $plan->name }}
@endsection

@section('page-title')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
        <h1 class="mb-2 mb-md-0 flex-shrink-0">@yield('title')</h1>
        <div class="section-header-button w-100 w-md-auto" style="margin-left: auto;">
            @can('subscription-plans-edit')
            <a href="{{ route('subscription-plans.edit', $plan) }}" class="btn btn-primary btn-sm mr-1">
                <i class="fas fa-edit"></i> {{ __('Edit') }}
            </a>
            @endcan
            <a href="{{ route('subscription-plans.index') }}" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> {{ __('Back') }}
            </a>
        </div>
    </div>
@endsection

@section('main')
<div class="section">
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h4>{{ __('Plan Details') }}</h4>
                </div>
                <div class="card-body">
                    <table class="table table-borderless">
                        <tr>
                            <th width="40%">{{ __('Name') }}</th>
                            <td>{{ $plan->name }}</td>
                        </tr>
                        <tr>
                            <th>{{ __('Price') }}</th>
                            <td>{{ $plan->formatted_price }}</td>
                        </tr>
                        <tr>
                            <th>{{ __('Billing Cycle') }}</th>
                            <td>{{ $plan->billing_cycle_label }}</td>
                        </tr>
                        <tr>
                            <th>{{ __('Commission Rate') }}</th>
                            <td>{{ $plan->commission_rate }}%</td>
                        </tr>
                        <tr>
                            <th>{{ __('Status') }}</th>
                            <td>
                                @if($plan->is_active)
                                    <span class="badge badge-success">{{ __('Active') }}</span>
                                @else
                                    <span class="badge badge-secondary">{{ __('Inactive') }}</span>
                                @endif
                            </td>
                        </tr>
                        @if($plan->description)
                        <tr>
                            <th>{{ __('Description') }}</th>
                            <td>{{ $plan->description }}</td>
                        </tr>
                        @endif
                        @if(!empty($plan->features))
                        <tr>
                            <th>{{ __('Features') }}</th>
                            <td>
                                <ul class="mb-0 pl-3">
                                    @foreach($plan->features as $f)
                                        @if($f)
                                        <li>{{ $f }}</li>
                                        @endif
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                        @endif
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h4>{{ __('Statistics') }}</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6">
                            <div class="small text-muted">{{ __('Total Subscribers') }}</div>
                            <h4>{{ $plan->subscriptions_count ?? 0 }}</h4>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted">{{ __('Active Subscribers') }}</div>
                            <h4>{{ $plan->active_subscriptions_count ?? 0 }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h4>{{ __('Subscribers') }}</h4>
                </div>
                <div class="card-body">
                    @if($subscribers->isEmpty())
                        <p class="text-muted mb-0">{{ __('No subscribers yet.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>{{ __('No.') }}</th>
                                        <th>{{ __('User') }}</th>
                                        <th>{{ __('Email') }}</th>
                                        <th>{{ __('Start Date') }}</th>
                                        <th>{{ __('End Date') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Auto Renew') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($subscribers as $i => $sub)
                                    <tr>
                                        <td>{{ $subscribers->firstItem() + $i }}</td>
                                        <td>{{ $sub->user->name ?? '-' }}</td>
                                        <td>{{ $sub->user->email ?? '-' }}</td>
                                        <td>{{ $sub->starts_at?->format('Y-m-d') }}</td>
                                        <td>{{ $sub->ends_at?->format('Y-m-d') ?? __('Lifetime') }}</td>
                                        <td>
                                            @if($sub->status === 'active')
                                                <span class="badge badge-success">{{ __('Active') }}</span>
                                            @elseif($sub->status === 'expired')
                                                <span class="badge badge-warning">{{ __('Expired') }}</span>
                                            @elseif($sub->status === 'cancelled')
                                                <span class="badge badge-danger">{{ __('Cancelled') }}</span>
                                            @else
                                                <span class="badge badge-secondary">{{ $sub->status }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $sub->auto_renew ? __('Yes') : __('No') }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3">
                            {{ $subscribers->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

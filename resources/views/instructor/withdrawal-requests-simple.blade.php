@extends('layouts.app')

@section('title', 'Withdrawal Requests')

@section('content')
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>{{ __('Withdrawal Requests') }}</h1>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>{{ __('All Withdrawal Requests') }}</h4>
                        </div>
                        <div class="card-body">
                            <p>Withdrawal requests page is working!</p>
                            <p>Total withdrawal requests: {{ \App\Models\WithdrawalRequest::count() }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection

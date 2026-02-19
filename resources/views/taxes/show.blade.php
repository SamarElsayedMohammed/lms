@extends('layouts.main')

@section('content')
<div class="container mt-4">
    <h2> {{ __('Tax Details') }} </h2>
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">{{ $tax->name }}</h5>
            <p class="card-text"><strong> {{ __('Rate:') }} </strong> {{ $tax->rate }}%</p>
            <p class="card-text"><strong> {{ __('Description:') }} </strong> {{ $tax->description }}</p>
            <a href="{{ route('taxes.index') }}" class="btn btn-secondary"> {{ __('Back to List') }} </a>
        </div>
    </div>
</div>
@endsection

@extends('layouts.main')

@section('content')
<div class="container mt-4">
    <h2> {{ __('Add Tax') }} </h2>
    <form action="{{ route('taxes.store') }}" method="POST"> @csrf <div class="form-group mb-3">
            <label for="name"> {{ __('Name') }} </label>
            <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required> @error(\'name\') <div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="form-group mb-3">
            <label for="rate"> {{ __('Rate (%)') }} </label>
            <input type="number" name="rate" id="rate" class="form-control @error('rate') is-invalid @enderror" value="{{ old('rate') }}" step="0.01" min="0" required> @error(\'rate\') <div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="form-group mb-3">
            <label for="description"> {{ __('Description') }} </label>
            <textarea name="description" id="description" class="form-control @error('description') is-invalid @enderror">{{ old('description') }}</textarea> @error(\'description\') <div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button type="submit" class="btn btn-success"> {{ __('Create') }} </button>
        <a href="{{ route('taxes.index') }}" class="btn btn-secondary"> {{ __('Cancel') }} </a>
    </form>
</div>
@endsection

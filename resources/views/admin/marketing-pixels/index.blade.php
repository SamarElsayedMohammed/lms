@extends('layouts.app')

@section('title', __('Marketing Pixels'))

@section('page-title')
    <h1 class="mb-0">{{ __('Marketing Pixels') }}</h1>
@endsection

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">{{ __('Add / Edit Pixel') }}</h4>
                    <form action="{{ route('marketing-pixels.store') }}" method="POST" class="create-form mb-4">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Platform') }}</label>
                                <select name="platform" class="form-control" required>
                                    <option value="">{{ __('Select Platform') }}</option>
                                    @foreach($platforms as $p)
                                    <option value="{{ $p }}">{{ ucfirst(str_replace('_', ' ', $p)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Pixel ID') }}</label>
                                <input type="text" name="pixel_id" class="form-control" required placeholder="{{ __('Enter Pixel ID') }}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">{{ __('Active') }}</label>
                                <div class="custom-switch mt-2">
                                    <input type="checkbox" name="is_active" value="1" class="custom-switch-input">
                                    <span class="custom-switch-indicator"></span>
                                </div>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                            </div>
                        </div>
                    </form>
                    <h4 class="card-title mb-3">{{ __('Configured Pixels') }}</h4>
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>{{ __('Platform') }}</th>
                                <th>{{ __('Pixel ID') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($pixels as $pixel)
                            <tr>
                                <td>{{ ucfirst(str_replace('_', ' ', $pixel->platform)) }}</td>
                                <td>{{ Str::limit($pixel->pixel_id, 40) }}</td>
                                <td>{{ $pixel->is_active ? __('Active') : __('Inactive') }}</td>
                                <td>
                                    <form action="{{ route('marketing-pixels.destroy', $pixel->id) }}" method="POST" class="d-inline" onsubmit="return confirm('{{ __('Delete this pixel?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm">{{ __('Delete') }}</button>
                                    </form>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="4">{{ __('No pixels configured') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

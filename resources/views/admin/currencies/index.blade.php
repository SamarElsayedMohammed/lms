@extends('layouts.app')

@section('title', __('Currencies'))

@section('page-title')
    <h1 class="mb-0">{{ __('Currencies') }}</h1>
@endsection

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">{{ __('Add Currency') }}</h4>
                    <form action="{{ route('currencies.store') }}" method="POST" class="create-form mb-4" id="currency-form">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">{{ __('Country Code') }}</label>
                                <input type="text" name="country_code" class="form-control" required maxlength="2" placeholder="EG">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">{{ __('Country Name') }}</label>
                                <input type="text" name="country_name" class="form-control" required placeholder="{{ __('Egypt') }}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">{{ __('Currency Code') }}</label>
                                <input type="text" name="currency_code" class="form-control" required maxlength="3" placeholder="EGP">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">{{ __('Symbol') }}</label>
                                <input type="text" name="currency_symbol" class="form-control" required placeholder="ج.م">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">{{ __('Rate to EGP') }}</label>
                                <input type="number" name="exchange_rate_to_egp" class="form-control" required min="0" step="0.0001" placeholder="1">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label">{{ __('Active') }}</label>
                                <div class="custom-control custom-switch mt-2">
                                    <input type="checkbox" name="is_active" value="1" class="custom-control-input" id="new_currency_active" checked>
                                    <label class="custom-control-label" for="new_currency_active"></label>
                                </div>
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">{{ __('Add') }}</button>
                            </div>
                        </div>
                    </form>
                    <h4 class="card-title mb-3">{{ __('Supported Currencies') }}</h4>
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>{{ __('Country') }}</th>
                                <th>{{ __('Currency') }}</th>
                                <th>{{ __('Exchange Rate to EGP') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($currencies as $c)
                            <tr>
                                <td>{{ $c->country_name }} ({{ $c->country_code }})</td>
                                <td>{{ $c->currency_code }} {{ $c->currency_symbol }}</td>
                                <td>
                                    <form class="d-inline update-rate-form" data-id="{{ $c->id }}">
                                        <input type="number" class="form-control form-control-sm d-inline-block" style="width:100px" 
                                            value="{{ $c->exchange_rate_to_egp }}" min="0" step="0.0001" name="exchange_rate_to_egp">
                                        <button type="submit" class="btn btn-sm btn-outline-primary ml-1">{{ __('Update') }}</button>
                                    </form>
                                </td>
                                <td>{{ $c->is_active ? __('Active') : __('Inactive') }}</td>
                                <td>
                                    <form action="{{ route('currencies.destroy', $c->id) }}" method="POST" class="d-inline" onsubmit="return confirm('{{ __('Delete this currency?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm">{{ __('Delete') }}</button>
                                    </form>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="5">{{ __('No currencies configured') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.querySelectorAll('.update-rate-form').forEach(f => {
        f.addEventListener('submit', function(e) {
            e.preventDefault();
            const id = this.dataset.id;
            const rate = this.querySelector('[name=exchange_rate_to_egp]').value;
            fetch('{{ url("currencies") }}/' + id, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ exchange_rate_to_egp: rate })
            }).then(r => r.json()).then(d => {
                alert(d.success ? d.message : d.message || 'Error');
            });
        });
    });
    document.getElementById('currency-form')?.addEventListener('submit', function(e) {
        e.preventDefault();
        fetch('{{ route("currencies.store") }}', {
            method: 'POST',
            body: new FormData(this),
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(r => r.json()).then(d => {
            alert(d.success ? d.message : d.message || 'Error');
            if (d.success) location.reload();
        });
    });
    </script>
@endsection

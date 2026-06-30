<div class="card" style="background-color: #F6F9FC; border-radius: 8px; padding: 24px; margin-bottom: 32px; border: 1px solid #E6EBF1;">
    @if(isset($title))
        <h3 style="color: #0A2540; font-size: 16px; margin: 0 0 12px 0; font-weight: 600;">{{ $title }}</h3>
    @endif
    {{ $slot }}
</div>

<div class="card" style="background-color: #F6F9FC; border-radius: 8px; padding: 24px; margin-bottom: 32px; border: 1px solid #E6EBF1;">
    <h3 style="color: #0A2540; font-size: 16px; margin: 0 0 16px 0; font-weight: 600;">{{ $title }}</h3>
    @if(isset($amount))
        <div class="invoice-amount" style="font-size: 32px; font-weight: 700; color: #0A2540; margin-bottom: 24px;">{{ $amount }}</div>
    @endif
    <table width="100%" cellpadding="0" cellspacing="0">
        {{ $slot }}
    </table>
</div>

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-top: 20px;">
    <tr>
        <td style="padding: 40px 0 0; text-align: center; border-top: 1px solid #f3f4f6;" class="footer-border">
            @include('emails.components.social-links')
            <p style="margin: 24px 0 12px; font-size: 14px; color: #9ca3af; font-family: 'Cairo', sans-serif;" class="footer-text">
                &copy; {{ date('Y') }} {{ config('app.name', 'Skillso') }}. جميع الحقوق محفوظة.
            </p>
            <p style="margin: 0 0 12px; font-size: 14px; color: #9ca3af; font-family: 'Cairo', sans-serif;" class="footer-text">
                <a href="mailto:{{ $supportEmail ?? 'support@skillso.com' }}" style="color: #9ca3af; text-decoration: underline;">{{ $supportEmail ?? 'support@skillso.com' }}</a>
                &nbsp;|&nbsp;
                <a href="{{ config('app.url') . '/terms' }}" style="color: #9ca3af; text-decoration: underline;">الشروط والأحكام</a>
            </p>
            @if(isset($unsubscribeUrl))
            <p style="margin: 0; font-size: 12px; color: #d1d5db; font-family: 'Cairo', sans-serif;" class="footer-text">
                لا ترغب في استقبال هذه الرسائل؟ <a href="{{ $unsubscribeUrl }}" style="color: #d1d5db; text-decoration: underline;">إلغاء الاشتراك</a>
            </p>
            @endif
        </td>
    </tr>
</table>

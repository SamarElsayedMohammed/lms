@extends('emails.layout')

@section('content')
<tr>
    @include('emails.components.hero', [
        'title' => 'إعادة تعيين كلمة المرور',
        'subtitle' => 'لقد تلقينا طلباً لإعادة تعيين كلمة المرور الخاصة بحسابك. يمكنك تغيير كلمة المرور بالنقر على الزر أدناه.',
        'image' => 'https://images.unsplash.com/photo-1614064641913-6b71a30f78cc?ixlib=rb-1.2.1&auto=format&fit=crop&w=1200&q=80'
    ])
</tr>
<tr>
    <td class="content">
        @include('emails.components.badge', ['type' => 'primary'])
            هذا الرابط صالح لمدة 60 دقيقة فقط
        @endcomponent

        @if(isset($otp))
            @include('emails.components.card', ['title' => 'رمز التحقق'])
                <div style="text-align: center; margin: 32px 0;">
                    <span style="display: inline-block; font-size: 32px; letter-spacing: 8px; font-weight: bold; color: #111827; background: #f3f4f6; padding: 16px 24px; border-radius: 8px;">
                        {{ $otp }}
                    </span>
                </div>
            @endcomponent
        @endif

        @if(isset($resetUrl))
            @include('emails.components.button', ['url' => $resetUrl])
                إعادة تعيين كلمة المرور
            @endcomponent
        @endif
        @include('emails.components.card', ['title' => 'نصيحة أمنية'])
            <p style="margin-bottom: 0;">استخدم كلمة مرور قوية تتكون من أحرف كبيرة وصغيرة وأرقام ورموز لضمان حماية حسابك.</p>
        @endcomponent
        
        <p style="font-size: 14px; color: #8898AA;">
            إذا لم تقم بطلب إعادة تعيين كلمة المرور، يرجى التواصل مع فريق الدعم إذا كنت تعتقد أن هناك نشاطاً مشبوهاً.
        </p>
    </td>
</tr>
@endsection

@extends('emails.layout')

@section('content')
<tr>
    @include('emails.components.hero', [
        'title' => 'تأكيد البريد الإلكتروني',
        'subtitle' => 'شكراً لتسجيلك في Skillso. يرجى تأكيد عنوان بريدك الإلكتروني للبدء في استخدام المنصة.',
        'image' => 'https://images.unsplash.com/photo-1596526131083-e8c633c948d2?ixlib=rb-1.2.1&auto=format&fit=crop&w=1200&q=80'
    ])
</tr>
<tr>
    <td class="content">
        @include('emails.components.badge', ['type' => 'warning'])
            ينتهي صلاحية هذا الرابط خلال 60 دقيقة
        @endcomponent

        @include('emails.components.button', ['url' => $verifyUrl])
            تأكيد البريد الإلكتروني
        @endcomponent
        
        <p style="font-size: 14px; margin-top: 24px; color: #8898AA;">
            إذا لم تقم بإنشاء حساب، يمكنك تجاهل هذه الرسالة بأمان.
        </p>
    </td>
</tr>
@endsection

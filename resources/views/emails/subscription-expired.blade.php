@extends('emails.layout')

@section('content')
<tr>
    @include('emails.components.hero', [
        'title' => 'لقد انتهى اشتراكك',
        'subtitle' => 'مرحباً ' . $userName . '، لقد انتهت صلاحية اشتراكك في باقة ' . $planName . '، وتم إيقاف وصولك إلى الميزات المدفوعة.',
        'image' => 'https://images.unsplash.com/photo-1584282563385-a7b2123512e0?ixlib=rb-1.2.1&auto=format&fit=crop&w=1200&q=80'
    ])
</tr>
<tr>
    <td class="content">
        @include('emails.components.badge', ['type' => 'error'])
            اشتراك منتهي
        @endcomponent

        @include('emails.components.card', ['title' => 'ماذا يعني هذا؟'])
            <p style="margin-bottom: 0;">لن تتمكن من الوصول إلى الدورات المدفوعة والشهادات حتى تقوم بتجديد اشتراكك. بياناتك وتقدمك محفوظة لدينا بأمان ولن تفقدها.</p>
        @endcomponent

        @include('emails.components.button', ['url' => $renewUrl])
            أعد تفعيل اشتراكك
        @endcomponent
    </td>
</tr>
@endsection

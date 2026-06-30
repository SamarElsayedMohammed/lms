@extends('emails.layout')

@section('content')
<tr>
    @include('emails.components.hero', [
        'title' => 'تمت الموافقة على الدفع اليدوي',
        'subtitle' => $subtitle ?? 'مرحباً ' . $userName . '، يسعدنا إخبارك بأنه تمت مراجعة إيصال التحويل البنكي الخاص بك بنجاح. لقد تم تفعيل طلبك بنجاح.',
        'image' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-1.2.1&auto=format&fit=crop&w=1200&q=80'
    ])
</tr>
<tr>
    <td class="content">
        @include('emails.components.badge', ['type' => 'success'])
            الاشتراك مفعل
        @endcomponent

        @include('emails.components.button', ['url' => $actionUrl])
            الذهاب إلى لوحة التحكم
        @endcomponent
    </td>
</tr>
@endsection

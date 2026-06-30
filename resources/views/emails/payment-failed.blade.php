@extends('emails.layout')

@section('content')
<tr>
    @include('emails.components.hero', [
        'title' => 'فشلت عملية الدفع',
        'subtitle' => 'مرحباً ' . $userName . '، نأسف لإبلاغك بأن عملية الدفع الخاصة باشتراكك لم تنجح.',
        'image' => 'https://images.unsplash.com/photo-1620228892338-e6b95b4528c1?ixlib=rb-1.2.1&auto=format&fit=crop&w=1200&q=80'
    ])
</tr>
<tr>
    <td class="content">
        @include('emails.components.badge', ['type' => 'error'])
            السبب: {{ $failReason }}
        @endcomponent

        @include('emails.components.card', ['title' => 'ماذا يجب أن تفعل؟'])
            <ul>
                <li>تأكد من وجود رصيد كافٍ في بطاقتك.</li>
                <li>تأكد من صحة بيانات البطاقة المدخلة.</li>
                <li>تواصل مع البنك المصدر للبطاقة إذا استمرت المشكلة.</li>
            </ul>
        @endcomponent

        @include('emails.components.button', ['url' => $retryUrl])
            حاول الدفع مرة أخرى
        @endcomponent
    </td>
</tr>
@endsection

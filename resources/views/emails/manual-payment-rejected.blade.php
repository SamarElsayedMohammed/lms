@extends('emails.layout')

@section('content')
<tr>
    @include('emails.components.hero', [
        'title' => 'مراجعة الدفع اليدوي',
        'subtitle' => $subtitle ?? 'مرحباً ' . $userName . '، لقد قمنا بمراجعة إيصال التحويل البنكي الخاص بك، ولكن للأسف لم نتمكن من قبوله.',
        'image' => 'https://images.unsplash.com/photo-1582213782179-e0d53f98f2ca?ixlib=rb-1.2.1&auto=format&fit=crop&w=1200&q=80'
    ])
</tr>
<tr>
    <td class="content">
        @include('emails.components.badge', ['type' => 'warning'])
            ملاحظة الإدارة: {{ $rejectReason }}
        @endcomponent
        
        <p>يرجى إعادة رفع إيصال الدفع بصورة واضحة ليتسنى لنا تفعيل اشتراكك في أسرع وقت.</p>

        @include('emails.components.button', ['url' => $uploadUrl])
            رفع إيصال جديد
        @endcomponent
    </td>
</tr>
@endsection

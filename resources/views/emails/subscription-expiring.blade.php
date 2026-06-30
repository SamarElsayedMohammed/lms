@extends('emails.layout')

@section('content')
<tr>
    @include('emails.components.hero', [
        'title' => 'تذكير: اقتراب انتهاء الاشتراك',
        'subtitle' => 'مرحباً ' . $userName . '، نود تذكيرك بأن اشتراكك في باقة ' . $planName . ' شارف على الانتهاء.',
        'image' => 'https://images.unsplash.com/photo-1506784983877-45594efa4cbe?ixlib=rb-1.2.1&auto=format&fit=crop&w=1200&q=80'
    ])
</tr>
<tr>
    <td class="content">
        @component('emails.components.badge', ['type' => 'warning'])
            ينتهي خلال {{ $daysRemaining }} أيام
        @endcomponent
        
        @component('emails.components.card', ['title' => 'مزايا التجديد'])
            <ul>
                <li>استمرار الوصول غير المحدود إلى دوراتك.</li>
                <li>الحصول على التحديثات والمحتوى الجديد أولاً بأول.</li>
                <li>الحفاظ على تقدمك التعليمي وشهاداتك بشكل آمن.</li>
            </ul>
        @endcomponent

        @component('emails.components.button', ['url' => $renewUrl])
            تجديد الاشتراك الآن
        @endcomponent
    </td>
</tr>
@endsection

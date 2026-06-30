@extends('emails.layout')

@section('content')
<tr>
    @include('emails.components.hero', [
        'title' => 'مرحباً بك في منصة Skillso',
        'subtitle' => 'يسعدنا انضمامك إلينا يا ' . $userName . '. لقد اتخذت الخطوة الأولى نحو تطوير مهاراتك والنجاح في مسيرتك المهنية.',
        'image' => 'https://images.unsplash.com/photo-1550751827-4bd374c3f58b?ixlib=rb-1.2.1&auto=format&fit=crop&w=1200&q=80'
    ])
</tr>
<tr>
    <td class="content">
        @include('emails.components.card', ['title' => 'خطواتك القادمة'])
            <ul>
                <li><strong>استكشاف الدورات:</strong> تصفح مكتبتنا الشاملة.</li>
                <li><strong>بناء مسارك التعليمي:</strong> خطط لمستقبلك.</li>
                <li><strong>التواصل:</strong> تعلم من الخبراء مباشرة.</li>
            </ul>
        @endcomponent

        @include('emails.components.button', ['url' => $actionUrl])
            ابدأ التعلم الآن
        @endcomponent
    </td>
</tr>
@endsection

@extends('emails.layout')

@section('content')
<tr>
    @include('emails.components.hero', [
        'title' => $notificationTitle,
        'image' => $imageUrl ?? 'https://images.unsplash.com/photo-1505330622279-bf7d7fc918f4?ixlib=rb-1.2.1&auto=format&fit=crop&w=1200&q=80'
    ])
</tr>
<tr>
    <td class="content">
        <div style="color: #425466; font-size: 16px; line-height: 24px; margin-bottom: 24px;">
            {!! $notificationContent !!}
        </div>

        @if(isset($actionUrl))
            @component('emails.components.button', ['url' => $actionUrl])
                {{ $actionText ?? 'عرض التفاصيل' }}
            @endcomponent
        @endif

        <p style="color: #8898AA; font-size: 13px; margin-top: 32px;">
            تاريخ الإرسال: <span dir="ltr">{{ $sentDate ?? now()->format('Y-m-d H:i') }}</span>
            <br/>
            تم الإرسال بواسطة: {{ $senderName ?? 'فريق إدارة Skillso' }}
        </p>
    </td>
</tr>
@endsection

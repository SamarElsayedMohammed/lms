@extends('emails.layout')

@section('content')
<tr>
    @include('emails.components.hero', [
        'title' => $notificationTitle,
        'image' => $imageUrl ?? 'https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?ixlib=rb-1.2.1&auto=format&fit=crop&w=1200&q=80'
    ])
</tr>
<tr>
    <td class="content">
        @if(isset($greeting))
            <h3 style="color: #0A2540; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">
                {{ $greeting }}
            </h3>
        @endif

        <div style="color: #425466; font-size: 16px; line-height: 24px; margin-bottom: 24px;">
            {!! nl2br(e($notificationContent)) !!}
        </div>

        @if(isset($actionUrl))
            @component('emails.components.button', ['url' => $actionUrl])
                {{ $actionText ?? 'عرض التفاصيل' }}
            @endcomponent
        @endif

        <p style="color: #8898AA; font-size: 13px; margin-top: 32px;">
            شكراً لثقتكم بنا،<br/>
            فريق {{ \App\Services\HelperService::systemSettings('app_name') ?? 'LMS' }}
        </p>
    </td>
</tr>
@endsection

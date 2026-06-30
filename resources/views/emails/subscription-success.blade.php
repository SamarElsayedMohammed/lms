@extends('emails.layout')

@section('content')
<tr>
    @include('emails.components.hero', [
        'title' => 'تم تفعيل الاشتراك بنجاح!',
        'subtitle' => 'تهانينا ' . $userName . '! لقد تم تفعيل اشتراكك بنجاح. يمكنك الآن الوصول إلى جميع الميزات المتاحة ضمن خطتك.',
        'image' => 'https://images.unsplash.com/photo-1551836022-d5d88e9218df?ixlib=rb-1.2.1&auto=format&fit=crop&w=1200&q=80'
    ])
</tr>
<tr>
    <td class="content">
        @include('emails.components.badge', ['type' => 'success'])
            اشتراك فعال
        @endcomponent

        @include('emails.components.invoice-card', ['title' => 'تفاصيل الاشتراك'])
            @include('emails.components.invoice-row', ['label' => 'الباقة', 'value' => $planName])
            @include('emails.components.invoice-row', ['label' => 'تاريخ البدء', 'value' => $startDate])
            @include('emails.components.invoice-row', ['label' => 'تاريخ الانتهاء', 'value' => $endDate])
        @endcomponent

        @include('emails.components.button', ['url' => $actionUrl])
            الذهاب إلى لوحة التحكم
        @endcomponent
    </td>
</tr>
@endsection

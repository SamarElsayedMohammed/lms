@extends('emails.layout')

@section('content')
<tr>
    @include('emails.components.hero', [
        'title' => 'تم الدفع بنجاح',
        'subtitle' => 'مرحباً ' . $userName . '، شكراً لك! لقد استلمنا دفعتك بنجاح. إليك إيصال الدفع الخاص بك.',
        'image' => 'https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?ixlib=rb-1.2.1&auto=format&fit=crop&w=1200&q=80'
    ])
</tr>
<tr>
    <td class="content">
        @include('emails.components.badge', ['type' => 'success'])
            عملية ناجحة
        @endcomponent

        @include('emails.components.invoice-card', ['title' => 'إيصال الدفع', 'amount' => $amount . ' EGP'])
            @include('emails.components.invoice-row', ['label' => 'الطلب', 'value' => $itemName ?? 'طلب جديد'])
            @include('emails.components.invoice-row', ['label' => 'رقم العملية', 'value' => $transactionId])
            @include('emails.components.invoice-row', ['label' => 'طريقة الدفع', 'value' => $paymentMethod])
        @endcomponent

        @include('emails.components.button', ['url' => $invoiceUrl, 'color' => '#0A2540'])
            تنزيل الفاتورة
        @endcomponent
    </td>
</tr>
@endsection

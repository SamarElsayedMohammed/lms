<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - {{ config('app.name') }}</title>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
    </style>
</head>
<body>
    <div class="payment-container">
        <div class="payment-header">
            <h1>Complete Your Payment</h1>
            <p>Secure payment powered by Razorpay</p>
        </div>

        <div class="payment-details">
            <h3>Payment Details</h3>
            <div class="detail-row payment-detail-row">
                <span class="detail-label payment-detail-label">Amount:</span>
                <span class="detail-value payment-detail-value">₹{{ number_format($amount / 100, 2) }}</span>
            </div>
            <div class="detail-row payment-detail-row">
                <span class="detail-label payment-detail-label">Order ID:</span>
                <span class="detail-value payment-detail-value">{{ $orderId }}</span>
            </div>
            <div class="detail-row payment-detail-row">
                <span class="detail-label payment-detail-label">Description:</span>
                <span class="detail-value payment-detail-value">{{ $description }}</span>
            </div>
        </div>

        <button id="pay-button" class="pay-button" onclick="openRazorpay()">
            Pay Now
        </button>

        <div class="loading payment-loading" id="loading">
            <div class="spinner payment-spinner"></div>
            <p>Processing payment...</p>
        </div>
    </div>

    <script>
        const razorpayKey = '{{ $razorpaySettings["razorpay_api_key"] }}';
        const orderId = '{{ $orderId }}';
        const amount = {{ $amount }};
        const currency = '{{ $currency }}';
        const name = '{{ $name }}';
        const description = '{{ $description }}';
        const prefillName = '{{ $prefillName }}';
        const prefillEmail = '{{ $prefillEmail }}';
        const orderNumber = '{{ request()->query("order_number", "") }}';
        const type = '{{ request()->query("type", "web") }}';

        function openRazorpay() {
            const payButton = document.getElementById('pay-button');
            const loading = document.getElementById('loading');
            
            payButton.disabled = true;
            loading.style.display = 'block';

            const options = {
                key: razorpayKey,
                amount: amount,
                currency: currency,
                name: name,
                description: description,
                order_id: orderId,
                prefill: {
                    name: prefillName,
                    email: prefillEmail
                },
                theme: {
                    color: '#F37254'
                },
                handler: function (response) {
                    // Payment successful
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '{{ route("razorpay.callback") }}';
                    
                    const orderNumberInput = document.createElement('input');
                    orderNumberInput.type = 'hidden';
                    orderNumberInput.name = 'order_number';
                    orderNumberInput.value = orderNumber || '{{ "ORD-" . uniqid() }}';
                    
                    const orderIdInput = document.createElement('input');
                    orderIdInput.type = 'hidden';
                    orderIdInput.name = 'razorpay_order_id';
                    orderIdInput.value = response.razorpay_order_id;
                    
                    const paymentIdInput = document.createElement('input');
                    paymentIdInput.type = 'hidden';
                    paymentIdInput.name = 'razorpay_payment_id';
                    paymentIdInput.value = response.razorpay_payment_id;
                    
                    const signatureInput = document.createElement('input');
                    signatureInput.type = 'hidden';
                    signatureInput.name = 'razorpay_signature';
                    signatureInput.value = response.razorpay_signature;
                    
                    const typeInput = document.createElement('input');
                    typeInput.type = 'hidden';
                    typeInput.name = 'type';
                    typeInput.value = type;
                    
                    const csrfInput = document.createElement('input');
                    csrfInput.type = 'hidden';
                    csrfInput.name = '_token';
                    csrfInput.value = '{{ csrf_token() }}';
                    
                    form.appendChild(orderNumberInput);
                    form.appendChild(orderIdInput);
                    form.appendChild(paymentIdInput);
                    form.appendChild(signatureInput);
                    form.appendChild(typeInput);
                    form.appendChild(csrfInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                },
                modal: {
                    ondismiss: function() {
                        payButton.disabled = false;
                        loading.style.display = 'none';
                    }
                }
            };

            const rzp = new Razorpay(options);
            rzp.open();
        }

        // Auto-open payment modal when page loads
        window.onload = function() {
            setTimeout(openRazorpay, 1000);
        };
    </script>
</body>
</html>

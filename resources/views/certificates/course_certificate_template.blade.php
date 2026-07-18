<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title>Certificate of Completion</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Arial', sans-serif;
            background-color: #ffffff;
            width: 800px;
            height: 566px;
            position: relative;
        }

        /* ===== الحاوية الرمادية الخارجية ===== */
        .wrapper {
            background-color: #e8e8e8; /* رمادي خفيف حول الشهادة */
            width: 800px;
            height: 566px;
            padding: 10px;
            position: absolute;
            top: 0;
            left: 0;
        }

        /* ===== الحاوية البيضاء الداخلية بالبوردر الأحمر ===== */
        .inner-content {
            background-color: #ffffff;
            width: 100%;
            height: 100%;
            border-radius: 12px;
            border: 3px solid #db3b2c; /* لون البوردر الأحمر */
            position: relative;
            text-align: center;
        }

        /* ===== المحتوى ===== */
        .header {
            margin-top: 40px;
        }

        .title {
            font-family: 'Times New Roman', Times, serif;
            font-size: 38px;
            letter-spacing: 8px;
            color: #1a1a1a;
            font-weight: bold;
            text-transform: uppercase;
        }

        .subtitle {
            font-size: 14px;
            letter-spacing: 4px;
            color: #4a4a4a;
            margin-top: 5px;
            margin-bottom: 25px;
            font-weight: 600;
        }

        /* تم إزالة لوجو المنتصف */

        .certifies-text {
            font-size: 16px;
            color: #333333;
            margin-bottom: 10px;
            font-weight: bold;
        }

        /* ===== اسم الطالب باللون الأحمر ===== */
        .student-name {
            font-size: 28px;
            font-weight: bold;
            color: #db3b2c; /* اللون الأحمر */
            margin-bottom: 15px;
            font-family: 'Arial', sans-serif;
        }

        .completion-text {
            font-size: 14px;
            color: #333333;
            font-weight: bold;
            margin-bottom: 25px;
        }

        .course-name {
            font-size: 18px;
            color: #222222;
            font-weight: bold;
        }

        /* ===== أسفل الشهادة ===== */
        .footer {
            position: absolute;
            bottom: 30px;
            width: 100%;
            left: 0;
        }

        /* ===== اللوجو أسفل اليسار ===== */
        .bottom-logo-img {
            position: absolute;
            bottom: 0px;
            left: 40px;
            max-width: 100px;
            max-height: 65px;
            object-fit: contain;
        }

        /* ===== قسم المدرب بالمنتصف ===== */
        .instructor-section {
            position: absolute;
            bottom: 0px;
            left: 50%;
            margin-left: -75px;
            width: 150px;
            text-align: center;
        }
        .instructor-line {
            border-top: 1px solid #000000;
            margin-bottom: 8px;
        }
        .instructor-text {
            font-size: 12px;
            font-weight: bold;
            color: #444444;
            letter-spacing: 2px;
        }

        /* ===== الكيو آر كود ورقم الشهادة أسفل اليمين ===== */
        .qr-section {
            position: absolute;
            bottom: 0px;
            right: 40px;
            text-align: center;
        }
        .qr-image {
            width: 60px;
            height: 60px;
            margin-bottom: 5px;
        }
        .cert-number {
            font-size: 9px;
            color: #666666;
        }
        .cert-date {
            font-size: 9px;
            color: #666666;
            margin-top: 2px;
        }
    </style>
</head>
<body>

    @php
        // توليد QR Code داخل القالب مباشرة
        $verifyUrl = $certificate->verification_url ?? url('/certificates/verify/' . ($certificate->verification_code ?? $certificate->certificate_number ?? ''));
        $qrDataUri = '';
        try {
            $result = (new \Endroid\QrCode\Builder\Builder(data: $verifyUrl, size: 100))->build();
            $qrDataUri = 'data:' . $result->getMimeType() . ';base64,' . base64_encode($result->getString());
        } catch (\Throwable $e) {}
    @endphp

    <div class="wrapper">
        <div class="inner-content">
            
            <div class="header">
                <div class="title">CERTIFICATE</div>
                <div class="subtitle">OF COMPLETION</div>
            </div>

            <!-- تم إزالة اللوجو من المنتصف بناءً على الطلب -->

            <div class="certifies-text">Certifies That</div>

            <div class="student-name">{{ $user->name ?? '[Student Name]' }}</div>

            <div class="completion-text">Has successfully completed the course</div>

            <div class="course-name">{{ $course->title ?? '[Course Name]' }}</div>

            <div class="footer">
                {{-- اللوجو في أسفل اليسار --}}
                <img src="{{ asset('img/logo.png') }}" class="bottom-logo-img" alt="Skillso" onerror="this.src='{{ asset('images/logo-3.png') }}'">

                {{-- توقيع المدرب --}}
                <div class="instructor-section">
                    <div class="instructor-line"></div>
                    <div class="instructor-text">INSTRUCTOR</div>
                </div>

                {{-- QR Code ورقم الشهادة --}}
                <div class="qr-section">
                    @if($qrDataUri)
                        <img src="{{ $qrDataUri }}" class="qr-image" alt="QR Code">
                    @endif
                    <div class="cert-number">ID: {{ $certificate->certificate_number ?? '' }}</div>
                    @if(!empty($certificate->verification_code))
                        <div class="cert-number" style="margin-top: 1px;">Verify: {{ $certificate->verification_code }}</div>
                    @endif
                    <div class="cert-date">{{ \Carbon\Carbon::parse($certificate->issued_date ?? now())->format('Y/m/d') }}</div>
                </div>
            </div>

        </div>
    </div>

</body>
</html>

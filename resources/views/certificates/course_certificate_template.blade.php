<!DOCTYPE html>
<html>
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
            font-family: Arial, sans-serif;
            background-color: #ffffff;
            width: 800px;
            height: 566px;
            position: relative;
            overflow: hidden;
        }

        /* ===== البوردر الأحمر الخارجي عبر table ===== */
        .outer-border {
            position: absolute;
            top: 14px;
            left: 14px;
            right: 14px;
            bottom: 14px;
            border: 3px solid #e01a0f;
            border-radius: 12px;
        }

        /* ===== خلفية النقوش الدائرية ===== */
        .watermark-row {
            font-size: 0;
            line-height: 0;
            color: #ececec;
            letter-spacing: 0;
        }
        .watermark-cell {
            display: inline-block;
            width: 32px;
            height: 32px;
            border: 1px solid #e5e5e5;
            border-radius: 50%;
        }

        /* ===== المحتوى الرئيسي ===== */
        .content {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            text-align: center;
            padding-top: 62px;
        }

        /* ===== عنوان CERTIFICATE ===== */
        .title {
            font-family: "Times New Roman", Times, serif;
            font-size: 36px;
            letter-spacing: 10px;
            color: #111111;
            font-weight: normal;
            margin-bottom: 4px;
        }

        /* ===== OF COMPLETION ===== */
        .subtitle {
            font-size: 12px;
            letter-spacing: 5px;
            color: #333333;
            margin-bottom: 24px;
        }

        /* ===== اللوجو ===== */
        .logo-container {
            margin-bottom: 16px;
        }
        .logo {
            max-height: 50px;
            max-width: 200px;
        }

        /* ===== Certifies That ===== */
        .certifies-text {
            font-size: 14px;
            color: #555555;
            margin-bottom: 16px;
            letter-spacing: 1px;
        }

        /* ===== اسم الطالب ===== */
        .student-name {
            font-size: 28px;
            font-weight: bold;
            color: #111111;
            margin-bottom: 14px;
            font-family: "Times New Roman", Times, serif;
        }

        /* ===== نص إتمام الكورس ===== */
        .completion-text {
            font-size: 14px;
            font-weight: bold;
            color: #222222;
            margin-bottom: 5px;
        }

        /* ===== اسم الكورس ===== */
        .course-name {
            font-size: 13px;
            color: #444444;
        }

        /* ===== اللوجو الدائري الأحمر ===== */
        .bottom-logo-circle {
            position: absolute;
            bottom: 24px;
            left: 42px;
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background-color: #cc1f10;
            text-align: center;
            padding-top: 14px;
        }
        .bottom-logo-arrow {
            font-size: 22px;
            color: #ffffff;
            font-weight: bold;
            line-height: 1;
        }
        .bottom-logo-text {
            color: #ffffff;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.5px;
        }

        /* ===== قسم المدرب ===== */
        .instructor-section {
            position: absolute;
            bottom: 28px;
            left: 50%;
            margin-left: -90px;
            width: 180px;
            text-align: center;
        }
        .instructor-line {
            border-top: 1px solid #999999;
            margin-bottom: 6px;
        }
        .instructor-text {
            font-size: 11px;
            font-weight: bold;
            color: #666666;
            letter-spacing: 3px;
        }

        /* ===== رقم الشهادة والتاريخ ===== */
        .cert-number {
            position: absolute;
            top: 22px;
            left: 42px;
            font-size: 10px;
            color: #888888;
        }
        .cert-date {
            position: absolute;
            bottom: 34px;
            right: 42px;
            font-size: 11px;
            color: #555555;
        }
    </style>
</head>
<body>

    {{-- خلفية النقوش الدائرية (CSS circles) --}}
    <div style="position:absolute;top:0;left:0;width:800px;height:566px;overflow:hidden;">
        @php $cols = 25; $rows = 18; @endphp
        @for($r = 0; $r < $rows; $r++)
            <div style="position:absolute;top:{{ $r * 32 }}px;left:0;width:800px;height:32px;">
                @for($c = 0; $c < $cols; $c++)
                    <div style="position:absolute;left:{{ $c * 32 }}px;top:0;width:30px;height:30px;border:1px solid #e8e8e8;border-radius:50%;"></div>
                @endfor
            </div>
        @endfor
        {{-- تدرج أبيض في المنتصف --}}
        <div style="position:absolute;top:0;left:0;width:800px;height:566px;background:radial-gradient(ellipse at 50% 45%, rgba(255,255,255,0.95) 40%, rgba(255,255,255,0.6) 75%, rgba(255,255,255,0) 100%);"></div>
    </div>

    {{-- البوردر الأحمر --}}
    <div class="outer-border"></div>

    {{-- رقم الشهادة --}}
    <div class="cert-number">
        {{ $certificate->certificate_number ?? '' }}
    </div>

    {{-- المحتوى الرئيسي --}}
    <div class="content">
        <div class="title">C E R T I F I C A T E</div>
        <div class="subtitle">OF COMPLETION</div>

        {{-- اللوجو --}}
        <div class="logo-container">
            <img src="{{ asset('img/logo.png') }}"
                 class="logo"
                 alt="Logo"
                 onerror="this.style.display='none'">
        </div>

        <div class="certifies-text">Certifies That</div>

        {{-- اسم الطالب --}}
        <div class="student-name">{{ $user->name ?? '[Student Name]' }}</div>

        {{-- نص إتمام الكورس --}}
        <div class="completion-text">Has successfully completed the course</div>

        {{-- اسم الكورس --}}
        <div class="course-name">{{ $course->title ?? '' }}</div>
    </div>

    {{-- اللوجو الدائري في أسفل اليسار --}}
    <div class="bottom-logo-circle">
        <div class="bottom-logo-arrow">&#8599;</div>
        <div class="bottom-logo-text">Skills</div>
    </div>

    {{-- قسم المدرب --}}
    <div class="instructor-section">
        <div class="instructor-line"></div>
        <div class="instructor-text">INSTRUCTOR</div>
    </div>

    {{-- التاريخ --}}
    <div class="cert-date">
        {{ \Carbon\Carbon::parse($certificate->issued_date ?? now())->format('F d, Y') }}
    </div>

</body>
</html>

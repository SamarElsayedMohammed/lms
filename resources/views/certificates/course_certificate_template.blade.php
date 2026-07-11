<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Certificate of Completion</title>
    <style>
        @page {
            margin: 0px;
        }
        body {
            margin: 0px;
            padding: 0px;
            font-family: Arial, sans-serif;
            background-color: #ffffff;
        }
        .container {
            width: 800px;
            height: 600px;
            box-sizing: border-box;
            background-image: url('{{ asset("storage/certificates/backgrounds/certificate_bg_1771627218.png") }}');
            background-size: cover;
            background-repeat: no-repeat;
            position: relative;
        }
        /* Fallback if image not found */
        .fallback-border {
            border: 4px solid #f33519;
            border-radius: 20px;
            width: 760px;
            height: 560px;
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 1;
        }
        .content {
            position: relative;
            z-index: 2;
            text-align: center;
            padding-top: 60px;
            width: 100%;
        }
        .title {
            font-family: "Times New Roman", Times, serif;
            font-size: 42px;
            letter-spacing: 8px;
            color: #000;
            margin-bottom: 5px;
        }
        .subtitle {
            font-family: Arial, sans-serif;
            font-size: 16px;
            letter-spacing: 4px;
            color: #333;
            margin-bottom: 40px;
        }
        .logo-container {
            margin-bottom: 15px;
        }
        .logo {
            max-height: 80px;
        }
        .certifies-text {
            font-size: 18px;
            color: #555;
            margin-bottom: 35px;
            font-weight: bold;
        }
        .student-name {
            font-size: 36px;
            font-weight: bold;
            color: #000;
            margin-bottom: 25px;
        }
        .course-name {
            font-size: 24px;
            color: #333;
            margin-bottom: 40px;
        }
        .footer {
            position: absolute;
            bottom: 40px;
            left: 0;
            right: 0;
            width: 100%;
            z-index: 2;
        }
        .instructor-section {
            position: absolute;
            bottom: 0;
            left: 50%;
            margin-left: -100px;
            width: 200px;
            text-align: center;
        }
        .instructor-line {
            width: 100%;
            border-top: 1px solid #000;
            margin-bottom: 5px;
        }
        .instructor-text {
            font-size: 16px;
            font-weight: bold;
            color: #555;
            letter-spacing: 2px;
        }
        .bottom-logo {
            position: absolute;
            bottom: -10px;
            left: 40px;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: #f33519; /* Just a placeholder styling */
            text-align: center;
            line-height: 80px;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }
        .certificate-number {
            position: absolute;
            top: 40px;
            left: 40px;
            font-size: 12px;
            color: #666;
            z-index: 2;
        }
        .date {
            position: absolute;
            bottom: 40px;
            right: 40px;
            font-size: 14px;
            color: #333;
            z-index: 2;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- If the background image doesn't load, this border will serve as fallback -->
        <div class="fallback-border" style="background-image: repeating-linear-gradient(-45deg, transparent, transparent 10px, #fafafa 10px, #fafafa 11px);"></div>
        
        <div class="certificate-number">
            {{ $certificate->certificate_number ?? 'CERT-' . strtoupper(uniqid()) }}
        </div>
        
        <div class="date">
            {{ \Carbon\Carbon::parse($certificate->issued_date ?? now())->format('F d, Y') }}
        </div>

        <div class="content">
            <div class="title">C E R T I F I C A T E</div>
            <div class="subtitle">OF COMPLETION</div>
            
            <div class="logo-container">
                <!-- Using a fallback logo if specific image is not found -->
                <img src="{{ asset('img/logo.png') }}" class="logo" alt="Skills Logo" onerror="this.style.display='none'">
            </div>
            
            <div class="certifies-text">Certifies That</div>
            
            <div class="student-name">{{ $user->name ?? '[Student Name]' }}</div>
            
            <div class="course-name">{{ $course->title ?? '[Course Name]' }}</div>
        </div>
        
        <div class="footer">
            <div class="bottom-logo">
                Skills
            </div>
            
            <div class="instructor-section">
                <div class="instructor-line"></div>
                <div class="instructor-text">INSTRUCTOR</div>
            </div>
        </div>
    </div>
</body>
</html>

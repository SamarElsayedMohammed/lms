<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <div class="certificate-container">
        <!-- Certificate Title (from certificates table) - Top Center -->
        @if(isset($certificateTemplate) && $certificateTemplate->title)
        <div style="position: absolute; top: 60px; left: 50%; transform: translateX(-50%); font-size: 32px; font-weight: bold; color: #333333; text-align: center;">
            {{ $certificateTemplate->title }}
        </div>
        @endif
        
        <!-- Certificate Subtitle (from certificates table) - Below Title -->
        @if(isset($certificateTemplate) && $certificateTemplate->subtitle)
        <div style="position: absolute; top: 110px; left: 50%; transform: translateX(-50%); font-size: 18px; color: #666666; text-align: center;">
            {{ $certificateTemplate->subtitle }}
        </div>
        @endif
        
        <!-- Certificate Number - Upper Left -->
        <div class="certificate-number">
            {{ $certificate->certificate_number ?? 'CERT-' . strtoupper(uniqid()) }}
        </div>
        
        <!-- Student Name - Center (Large, Bold) -->
        <div class="student-name">
            {{ $user->name ?? '[Student Name]' }}
        </div>
        
        <!-- Course Name - Upper Right -->
        <div class="course-name">
            {{ $course->title ?? '[Course Name]' }}
        </div>
        
        <!-- Completion Date - Bottom Right -->
        <div class="completion-date">
            Date: {{ \Carbon\Carbon::parse($certificate->issued_date ?? now())->format('F d, Y') }}
        </div>
        
        <!-- Signature Image (from certificates table) - Bottom Right (Above Signature Text) -->
        @if(isset($certificateTemplate) && $certificateTemplate->signature_image)
        <div style="position: absolute; bottom: 80px; right: 50px; width: 150px; height: 60px;">
            <img src="{{ asset('storage/' . $certificateTemplate->signature_image) }}" style="max-width: 100%; max-height: 100%; object-fit: contain;" alt="Signature">
        </div>
        @endif
        
        <!-- Signature Text (from certificates table) - Bottom Right (Below Signature Image) -->
        @if(isset($certificateTemplate) && $certificateTemplate->signature_text)
        <div style="position: absolute; bottom: 40px; right: 50px; font-size: 14px; color: #666666; text-align: center; width: 150px;">
            {{ $certificateTemplate->signature_text }}
        </div>
        @endif
    </div>
</body>
</html>

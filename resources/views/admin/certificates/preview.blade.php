<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Preview - {{ $certificate->name }}</title>
</head>
<body>
    <div class="preview-controls">
        <a href="{{ route('admin.certificates.index') }}" class="btn btn-secondary">
            <span class="btn-text-full">Back to List</span>
            <span class="btn-text-short">Back</span>
        </a>
        <a href="{{ route('admin.certificates.edit', $certificate) }}" class="btn btn-primary">
            <span class="btn-text-full">Edit Certificate</span>
            <span class="btn-text-short">Edit</span>
        </a>
        <button onclick="window.print()" class="btn btn-primary">
            <span class="btn-text-full">Print Preview</span>
            <span class="btn-text-short">Print</span>
        </button>
    </div>

    <div class="certificate-container">
        <div class="certificate-preview {{ !$certificate->background_image ? 'placeholder-bg' : '' }}" 
             id="certificate-preview"
             style="{{ $certificate->background_image ? 'background-image: url(' . $certificate->background_image_url . ')' : '' }}; position: relative; width: 100%; min-height: 400px; height: 600px; background-size: cover; background-position: center; background-repeat: no-repeat;">
            
            @php
                $templateSettings = is_string($certificate->template_settings) 
                    ? json_decode($certificate->template_settings, true) 
                    : ($certificate->template_settings ?? []);
                
                $canvasWidth = $templateSettings['width'] ?? 800;
                $canvasHeight = $templateSettings['height'] ?? 600;
                
                // Sample data for preview
                $replacements = [
                    '[Student Name]' => 'John Doe',
                    '[Course Name]' => 'Sample Course Name',
                    '[Completion Date]' => date('F j, Y'),
                    '{{certificate_number}}' => 'CERT-001',
                    '{{student_name}}' => 'John Doe',
                    '{{course_name}}' => 'Sample Course Name',
                    '{{completion_date}}' => date('F j, Y'),
                    '{{signature_text}}' => $certificate->signature_text ?? 'Director',
                    '{{certificate_title}}' => $certificate->title ?? 'Certificate of Completion',
                    '{{certificate_subtitle}}' => $certificate->subtitle ?? 'This is to certify that',
                ];
            @endphp

            @if(isset($templateSettings['elements']) && is_array($templateSettings['elements']) && count($templateSettings['elements']) > 0)
                {{-- Render elements from template_settings --}}
                @foreach($templateSettings['elements'] as $element)
                    @php
                        $content = $element['content'] ?? '';
                        $content = str_replace(array_keys($replacements), array_values($replacements), $content);
                        $styles = $element['styles'] ?? [];
                        $x = $element['x'] ?? 0;
                        $y = $element['y'] ?? 0;
                        $width = $element['width'] ?? 'auto';
                        $height = $element['height'] ?? 'auto';
                        $elementType = $element['type'] ?? 'text';
                        
                        // Check if element should be centered
                        $elementId = $element['id'] ?? '';
                        $isCentered = $element['isCentered'] ?? false;
                        $leftStyle = $element['leftStyle'] ?? null;
                        $transform = $element['transform'] ?? null;
                        
                        // Check if element is marked as centered or has centering transform
                        // Only use saved centering info, don't force center based on element ID
                        $shouldCenter = $isCentered || 
                                       $leftStyle === '50%' || 
                                       (is_string($transform) && strpos($transform, 'translateX(-50%)') !== false);
                        
                        // Date element should NOT be centered - it should be on the left
                        if ($elementId === 'date-element') {
                            $shouldCenter = false;
                        }
                        
                        // Check if element should be on right side (signature element)
                        $isRightAligned = false;
                        $rightValue = null;
                        if ($elementId === 'signature-element') {
                            // Check if element has right style
                            $rightStyle = $element['rightStyle'] ?? null;
                            if ($rightStyle && $rightStyle !== 'auto') {
                                $isRightAligned = true;
                                $rightValue = $rightStyle;
                            } else {
                                // Check if x position is on the right side
                                $canvasWidth = $templateSettings['width'] ?? 800;
                                if ($x > ($canvasWidth / 2)) {
                                    $isRightAligned = true;
                                    $rightValue = ($canvasWidth - $x - (is_numeric($width) ? $width : 200)) . 'px';
                                }
                            }
                        }
                        
                        // Check if signature element has image
                        $hasSignatureImage = false;
                        $signatureImageSrc = '';
                        if ($elementId === 'signature-element') {
                            // Check saved image info
                            if (isset($element['hasImage']) && $element['hasImage'] && isset($element['imageSrc'])) {
                                $hasSignatureImage = true;
                                $signatureImageSrc = $element['imageSrc'];
                            } elseif ($certificate->signature_image) {
                                // Fallback to certificate's signature image
                                $hasSignatureImage = true;
                                $signatureImageSrc = $certificate->signature_image_url;
                            }
                        }
                    @endphp
                    
                    @php
                        $isImage = false;
                        $imageSrc = '';
                        
                        // Check if element type is image
                        if ($elementType === 'image') {
                            $isImage = true;
                            $imageSrc = $content;
                        } 
                        // Check if content is an image URL
                        elseif (strpos($content, 'http') === 0 || strpos($content, 'https') === 0) {
                            $isImage = true;
                            $imageSrc = $content;
                        }
                        // Check if content contains storage path
                        elseif (strpos($content, 'storage/') !== false || strpos($content, 'certificates/') !== false) {
                            $isImage = true;
                            $imageSrc = strpos($content, 'http') === 0 ? $content : asset('storage/' . ltrim(str_replace('storage/', '', $content), '/'));
                        }
                        // Check if content is an img tag
                        elseif (strpos($content, '<img') !== false) {
                            $isImage = true;
                            // Extract src from img tag
                            preg_match('/src=["\']([^"\']+)["\']/', $content, $matches);
                            $imageSrc = $matches[1] ?? $content;
                        }
                    @endphp
                    
                    @if($isImage)
                        {{-- Image element --}}
                        <div class="certificate-element" 
                             style="position: absolute; 
                                    @if($shouldCenter) left: 50%; transform: translateX(-50%); @elseif($isRightAligned) right: {{ $rightValue }}; left: auto; @else left: {{ $x }}px; @endif
                                    top: {{ $y }}px; 
                                    width: {{ $width }}px; 
                                    height: {{ $height }}px;
                                    @if(isset($styles['opacity'])) opacity: {{ $styles['opacity'] }}; @endif
                                    @if(isset($styles['backgroundColor'])) background-color: {{ $styles['backgroundColor'] }}; @endif">
                            <img src="{{ $imageSrc }}" 
                                 alt="Certificate Element" 
                                 style="width: 100%; height: 100%; object-fit: contain;">
                        </div>
                    @else
                        {{-- Text element or signature element (may contain image) --}}
                        <div class="certificate-element" 
                             style="position: absolute; 
                                    @if($shouldCenter) left: 50%; transform: translateX(-50%); @elseif($isRightAligned) right: {{ $rightValue }}; left: auto; @else left: {{ $x }}px; @endif
                                    top: {{ $y }}px; 
                                    width: {{ $width }}px; 
                                    height: {{ $height }}px;
                                    @if(isset($styles['fontSize'])) font-size: {{ $styles['fontSize'] }}; @endif
                                    @if(isset($styles['color'])) color: {{ $styles['color'] }}; @endif
                                    @if(isset($styles['fontWeight'])) font-weight: {{ $styles['fontWeight'] }}; @endif
                                    @if(isset($styles['fontStyle'])) font-style: {{ $styles['fontStyle'] }}; @endif
                                    @if(isset($styles['fontFamily'])) font-family: {{ $styles['fontFamily'] }}; @endif
                                    @if(isset($styles['textAlign'])) text-align: {{ $styles['textAlign'] }}; @endif
                                    @if(isset($styles['lineHeight'])) line-height: {{ $styles['lineHeight'] }}; @endif
                                    @if(isset($styles['letterSpacing'])) letter-spacing: {{ $styles['letterSpacing'] }}; @endif
                                    @if(isset($styles['textDecoration'])) text-decoration: {{ $styles['textDecoration'] }}; @endif
                                    @if(isset($styles['backgroundColor'])) background-color: {{ $styles['backgroundColor'] }}; @endif
                                    @if(isset($styles['opacity'])) opacity: {{ $styles['opacity'] }}; @endif
                                    word-wrap: break-word;
                                    padding: 5px;
                                    text-align: center;">
                            @if($hasSignatureImage)
                                <img src="{{ $signatureImageSrc }}" alt="Signature" style="max-width: 150px; max-height: 80px; display: block; margin: 0 auto 5px auto;">
                            @endif
                            {!! $content !!}
                        </div>
                    @endif
                @endforeach
            @else
                {{-- Fallback to default layout if no template_settings --}}
            <div class="certificate-content">
                @if($certificate->title)
                <div class="certificate-title">{{ $certificate->title }}</div>
                @endif

                @if($certificate->subtitle)
                <div class="certificate-subtitle">{{ $certificate->subtitle }}</div>
                @endif

                <div class="certificate-body">
                    <p>This is to certify that</p>
                    <p><strong>John Doe</strong></p>
                    <p>has successfully completed the course</p>
                    <p><strong>Sample Course Name</strong></p>
                    <p>on this day of <strong>{{ date('F j, Y') }}</strong></p>
                </div>
            </div>

            @if($certificate->signature_image || $certificate->signature_text)
            <div class="certificate-signature">
                @if($certificate->signature_image)
                <img src="{{ $certificate->signature_image_url }}" alt="Signature" class="signature-image">
                @endif
                @if($certificate->signature_text)
                <div class="signature-text">{{ $certificate->signature_text }}</div>
                @endif
            </div>
            @endif

            <div class="certificate-date">
                Date: {{ date('F j, Y') }}
            </div>
            @endif
        </div>
    </div>

    <script>
        // Print functionality
        function printCertificate() {
            window.print();
        }

        // Add some interactive features
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Certificate preview loaded');
        });
    </script>
</body>
</html>

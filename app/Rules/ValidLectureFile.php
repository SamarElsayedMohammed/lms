<?php

namespace App\Rules;

use App\Services\HelperService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidLectureFile implements ValidationRule
{
    private const DANGEROUS_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
        'exe', 'sh', 'bat', 'cmd', 'pl', 'cgi', 'py', 'js', 'jar', 'vbs',
        'com', 'scr', 'msi', 'bin', 'dll', 'asp', 'aspx', 'jsp',
    ];

    public function __construct(
        protected $type,
        protected $lectureType,
        protected $allowedTypes,
    ) {}

    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->type === 'lecture' && $this->lectureType === 'file') {
            if (empty($value) || !is_object($value) || !method_exists($value, 'getClientOriginalExtension')) {
                $fail("The $attribute field is required when lecture type is file.");
                return;
            }

            $ext = strtolower((string) $value->getClientOriginalExtension());
            $detectedExt = strtolower((string) ($value->extension() ?? ''));

            if (in_array($ext, self::DANGEROUS_EXTENSIONS, true) || in_array($detectedExt, self::DANGEROUS_EXTENSIONS, true)) {
                $fail("The $attribute contains an insecure file type.");
                return;
            }

            if (!in_array($ext, $this->allowedTypes, true)) {
                $fail("The $attribute must be one of the following types: " . implode(', ', $this->allowedTypes) . '.');
                return;
            }

            // Get max video upload size from settings (in MB), default to 10MB
            $maxVideoSize = HelperService::systemSettings('max_video_upload_size');
            $maxSizeMB = !empty($maxVideoSize) ? (float) $maxVideoSize : 10;
            $maxSizeBytes = $maxSizeMB * 1024 * 1024;

            if ($value->getSize() > $maxSizeBytes) {
                $fail("The $attribute must not exceed {$maxSizeMB}MB.");
            }
        }
    }
}

<?php

namespace App\Rules;

use App\Services\HelperService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidLectureFile implements ValidationRule
{
    public function __construct(
        protected $type,
        protected $lectureType,
        protected $allowedTypes,
    ) {}

    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->type === 'lecture' && $this->lectureType === 'file') {
            if (empty($value)) {
                $fail("The $attribute field is required when lecture type is file.");
                return;
            }

            $ext = strtolower((string) $value->getClientOriginalExtension());
            if (!in_array($ext, $this->allowedTypes)) {
                $fail("The $attribute must be one of the following types: " . implode(', ', $this->allowedTypes) . '.');
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

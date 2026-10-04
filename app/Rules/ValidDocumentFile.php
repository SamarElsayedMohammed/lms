<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidDocumentFile implements ValidationRule
{
    private const DANGEROUS_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
        'exe', 'sh', 'bat', 'cmd', 'pl', 'cgi', 'py', 'js', 'jar', 'vbs',
        'com', 'scr', 'msi', 'bin', 'dll', 'asp', 'aspx', 'jsp',
    ];

    public function __construct(
        protected null|string $type,
        protected null|string $documentType,
        protected array $allowedTypes,
    ) {}

    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->type === 'document' && $this->documentType === 'file') {
            if (!$value || !is_object($value) || !method_exists($value, 'getClientOriginalExtension')) {
                $fail("The $attribute field is required for document file.");
                return;
            }

            $ext = strtolower((string) $value->getClientOriginalExtension());
            $detectedExt = strtolower((string) ($value->extension() ?? ''));

            if (in_array($ext, self::DANGEROUS_EXTENSIONS, true) || in_array($detectedExt, self::DANGEROUS_EXTENSIONS, true)) {
                $fail("The $attribute contains an insecure file type.");
                return;
            }

            if (!in_array($ext, $this->allowedTypes, true)) {
                $fail("The $attribute must be one of: " . implode(', ', $this->allowedTypes) . '.');
            }
        }
    }
}

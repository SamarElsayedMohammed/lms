<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidDocumentFile implements ValidationRule
{
    public function __construct(
        protected null|string $type,
        protected null|string $documentType,
        protected array $allowedTypes,
    ) {}

    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->type === 'document' && $this->documentType === 'file') {
            if (!$value) {
                $fail("The $attribute field is required for document file.");
                return;
            }

            $ext = strtolower((string) $value->getClientOriginalExtension());
            if (!in_array($ext, $this->allowedTypes)) {
                $fail("The $attribute must be one of: " . implode(', ', $this->allowedTypes) . '.');
            }
        }
    }
}

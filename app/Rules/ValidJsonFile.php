<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidJsonFile implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value) {
            return; // Skip if file is not provided (nullable)
        }

        // Check file extension - must be .json
        $extension = strtolower((string) $value->getClientOriginalExtension());

        if ($extension !== 'json') {
            $fail("The {$attribute} must be a JSON file (.json extension only).");
            return;
        }

        // Check MIME type
        $mimeType = $value->getMimeType();
        $allowedMimeTypes = ['application/json', 'text/json', 'application/octet-stream'];

        // For some systems, JSON files might have application/octet-stream MIME type
        // So we'll also validate by reading the file content
        if (!in_array($mimeType, $allowedMimeTypes)) {
            // Try to validate by reading file content
            try {
                $content = file_get_contents($value->getRealPath());
                json_decode($content, true, 512, JSON_THROW_ON_ERROR);

                // If JSON parsing succeeds, it's a valid JSON file
            } catch (\JsonException) {
                $fail("The {$attribute} must be a valid JSON file.");
                return;
            }
        }

        // Validate JSON content
        try {
            $content = file_get_contents($value->getRealPath());
            json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $fail("The {$attribute} contains invalid JSON format.");
            return;
        }
    }
}

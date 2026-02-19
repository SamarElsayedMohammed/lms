<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidQuizOptions implements ValidationRule
{
    public function __construct(
        protected null|string $type,
    ) {}

    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->type === 'quiz' && (!is_array($value) || empty($value))) {
            $fail("The $attribute field is required and must be an array when type is quiz.");
        }
    }
}

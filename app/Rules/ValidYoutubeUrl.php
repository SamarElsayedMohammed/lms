<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidYoutubeUrl implements ValidationRule
{
    public function __construct(
        protected null|string $type,
        protected null|string $lectureType,
    ) {}

    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->type === 'lecture' && $this->lectureType === 'youtube_url') {
            if (empty($value)) {
                $fail("The $attribute field is required when lecture type is youtube_url.");
                return;
            }

            if (
                !str_contains((string) $value, 'youtube.com/watch?v=')
                && !str_contains((string) $value, 'youtube.com/shorts/')
                && !str_contains((string) $value, 'youtu.be/')
            ) {
                $fail('The lecture youtube url must be a valid YouTube video or Youtube short URL.');
            }
        }
    }
}

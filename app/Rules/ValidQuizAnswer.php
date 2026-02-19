<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidQuizAnswer implements ValidationRule
{
    public function __construct(
        protected null|string $type,
        protected array $quizData,
    ) {}

    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->type !== 'quiz') {
            return;
        }

        if (!empty($this->quizData)) {
            foreach ($this->quizData as $question) {
                $options = $question['option_data'] ?? [];
                $correctCount = 0;
                foreach ($options as $opt) {
                    if (empty($opt['is_correct'])) {
                        continue;
                    }

                    $correctCount++;
                }
                if ($correctCount === 0) {
                    $fail('Each question must have at least one correct answer.');
                }
            }
        }
    }
}

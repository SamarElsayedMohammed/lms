<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class WebinarRegistrationDeniedException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code,
        public readonly string $errorCode,
    ) {
        parent::__construct($message, $code);
    }
}

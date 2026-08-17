<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class PromoQuotaExceededException extends Exception
{
    public function __construct(string $message = 'كوبون الخصم استنفذ الحد الأقصى للاستخدام', int $code = 422)
    {
        parent::__construct($message, $code);
    }
}

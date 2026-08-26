<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\WebinarRegistration;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebinarRegistered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public WebinarRegistration $registration)
    {
    }
}

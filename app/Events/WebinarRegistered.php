<?php

namespace App\Events;

use App\Models\WebinarRegistration;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebinarRegistered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public WebinarRegistration $registration;

    /**
     * Create a new event instance.
     */
    public function __construct(WebinarRegistration $registration)
    {
        $this->registration = $registration;
    }
}

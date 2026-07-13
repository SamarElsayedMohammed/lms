<?php

namespace App\Events;

use App\Models\Webinar;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebinarStartingSoon
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Webinar $webinar;

    /**
     * Create a new event instance.
     */
    public function __construct(Webinar $webinar)
    {
        $this->webinar = $webinar;
    }
}

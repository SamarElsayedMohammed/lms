<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionExpiryNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public int $daysUntilExpiry
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->daysUntilExpiry === 1
            ? __('Your subscription expires in 24 hours')
            : __('Your subscription expires in :days days', ['days' => $this->daysUntilExpiry]);
        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-expiry',
        );
    }
}

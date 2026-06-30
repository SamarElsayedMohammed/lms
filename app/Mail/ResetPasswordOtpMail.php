<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class ResetPasswordOtpMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param array{address: string, name: string} $from
     */
    public function __construct(
        public readonly User $user,
        public readonly string $otp,
        public readonly string $appName,
        public readonly int $expiryMinutes,
        private readonly array $fromAddress,
        private readonly string $mailSubject,
    ) {}

    public function build(): self
    {
        return $this->from($this->fromAddress['address'], $this->fromAddress['name'])
            ->subject($this->mailSubject)
            ->view('emails.reset-password')
            ->with([
                'user' => $this->user,
                'otp' => $this->otp,
                'appName' => $this->appName,
                'expiryMinutes' => $this->expiryMinutes,
            ]);
    }
}

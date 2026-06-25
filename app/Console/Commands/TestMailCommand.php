<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mail\BrevoTransactionalMailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

final class TestMailCommand extends Command
{
    protected $signature = 'mail:test {email : Recipient email address}';

    protected $description = 'Send a test email to verify SMTP/Brevo configuration';

    public function handle(BrevoTransactionalMailService $brevoMailService): int
    {
        $email = (string) $this->argument('email');
        $from = (string) config('mail.from.address');
        $driver = (string) config('mail.default');

        $this->info("Mail driver: {$driver}");
        $this->info("From address: {$from}");
        $this->info('Brevo API: ' . ($brevoMailService->isConfigured() ? 'configured' : 'not configured'));

        if ($from === '' || $from === 'hello@example.com') {
            $this->warn('MAIL_FROM_ADDRESS must be a verified sender in Brevo.');
        }

        $subject = 'Skillso mail test — ' . now()->toDateTimeString();
        $html = '<p>If you received this, mail delivery is working.</p><p>Sent at: ' . e(now()->toDateTimeString()) . '</p>';

        try {
            if ($brevoMailService->isConfigured()) {
                $result = $brevoMailService->sendHtml($email, 'Test User', $subject, $html);
                $this->info('Sent via Brevo API. messageId: ' . ($result['message_id'] ?? 'n/a'));

                return self::SUCCESS;
            }

            Mail::html($html, static function ($message) use ($email, $subject, $from): void {
                if ($from !== '' && $from !== 'hello@example.com') {
                    $message->from($from, (string) config('mail.from.name'));
                }

                $message->to($email)->subject($subject);
            });

            $this->info('Sent via SMTP.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Mail failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}

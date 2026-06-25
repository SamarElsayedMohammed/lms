<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mail\BrevoTransactionalMailService;
use App\Services\Mail\MailFromResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

final class TestMailCommand extends Command
{
    protected $signature = 'mail:test {email : Recipient email address}';

    protected $description = 'Send a test email to verify SMTP/Brevo configuration';

    public function handle(
        BrevoTransactionalMailService $brevoMailService,
        MailFromResolver $mailFromResolver,
    ): int {
        $email = (string) $this->argument('email');
        $driver = (string) config('mail.default');

        $this->info("Mail driver: {$driver}");
        $this->info('Brevo API: ' . ($brevoMailService->isConfigured() ? 'configured' : 'not configured'));

        try {
            $from = $mailFromResolver->resolve();
            $this->info("From address: {$from['address']} ({$from['name']})");
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
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
                $message->from($from['address'], $from['name'])
                    ->to($email)
                    ->subject($subject);
            });

            $this->info('Sent via SMTP.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Mail failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}

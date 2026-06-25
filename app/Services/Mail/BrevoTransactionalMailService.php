<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class BrevoTransactionalMailService
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.brevo.api_key', '')) !== '';
    }

    /**
     * @return array{message_id: string|null}
     */
    public function sendHtml(string $toEmail, string $toName, string $subject, string $html): array
    {
        $apiKey = trim((string) config('services.brevo.api_key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('Brevo API key is not configured (BREVO_API_KEY).');
        }

        $fromAddress = trim((string) config('mail.from.address', ''));
        $fromName = trim((string) config('mail.from.name', ''));

        if ($fromAddress === '' || $fromAddress === 'hello@example.com') {
            throw new RuntimeException(
                'MAIL_FROM_ADDRESS must be a verified sender in Brevo (not hello@example.com).',
            );
        }

        $response = Http::timeout(20)
            ->withHeaders([
                'api-key' => $apiKey,
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ])
            ->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => [
                    'name' => $fromName !== '' ? $fromName : $fromAddress,
                    'email' => $fromAddress,
                ],
                'to' => [
                    [
                        'email' => $toEmail,
                        'name' => $toName !== '' ? $toName : $toEmail,
                    ],
                ],
                'subject' => $subject,
                'htmlContent' => $html,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'Brevo API rejected the email: HTTP ' . $response->status() . ' — ' . $response->body(),
            );
        }

        /** @var array<string, mixed>|null $payload */
        $payload = $response->json();

        return [
            'message_id' => isset($payload['messageId']) ? (string) $payload['messageId'] : null,
        ];
    }
}

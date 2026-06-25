<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class BrevoTransactionalMailService
{
    public function __construct(
        private readonly MailFromResolver $mailFromResolver,
    ) {}

    public function isConfigured(): bool
    {
        return trim((string) config('services.brevo.api_key', '')) !== '';
    }

    /**
     * @return array{message_id: string|null, from: string}
     */
    public function sendHtml(string $toEmail, string $toName, string $subject, string $html): array
    {
        $apiKey = trim((string) config('services.brevo.api_key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('Brevo API key is not configured (BREVO_API_KEY).');
        }

        $from = $this->mailFromResolver->resolve();

        $response = Http::timeout(20)
            ->withHeaders([
                'api-key' => $apiKey,
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ])
            ->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => [
                    'name' => $from['name'],
                    'email' => $from['address'],
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
            'from' => $from['address'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Services\HelperService;
use RuntimeException;

final class MailFromResolver
{
    public function address(): string
    {
        foreach ($this->candidates() as $candidate) {
            if ($this->isValidAddress($candidate)) {
                return strtolower(trim($candidate));
            }
        }

        return '';
    }

    public function name(): string
    {
        $configuredName = trim((string) config('mail.from.name', ''));

        if ($configuredName !== '' && !in_array($configuredName, ['Example', '${APP_NAME}'], true)) {
            return $configuredName;
        }

        $appName = HelperService::systemSettings('app_name');

        if (is_string($appName) && trim($appName) !== '') {
            return trim($appName);
        }

        return (string) config('app.name', 'Skillso');
    }

    public function isConfigured(): bool
    {
        return $this->address() !== '';
    }

    /**
     * @return array{address: string, name: string}
     */
    public function resolve(): array
    {
        $address = $this->address();

        if ($address === '') {
            throw new RuntimeException(
                'Mail sender is not configured. Set MAIL_FROM_ADDRESS in .env to a Brevo-verified sender, '
                . 'or set admin_email in system settings, or use your Brevo login email as MAIL_USERNAME.',
            );
        }

        return [
            'address' => $address,
            'name' => $this->name(),
        ];
    }

    /**
     * @return list<string|null>
     */
    private function candidates(): array
    {
        return [
            config('mail.from.address'),
            HelperService::systemSettings('admin_email'),
            config('mail.mailers.smtp.username'),
        ];
    }

    private function isValidAddress(mixed $email): bool
    {
        if (!is_string($email)) {
            return false;
        }

        $email = trim($email);

        if ($email === '' || in_array(strtolower($email), ['hello@example.com', 'null'], true)) {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

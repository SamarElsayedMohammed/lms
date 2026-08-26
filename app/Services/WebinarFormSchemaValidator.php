<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Webinar;
use Illuminate\Validation\ValidationException;

class WebinarFormSchemaValidator
{
    public const MAX_ANSWER_BYTES = 8000;
    public const MAX_FIELD_COUNT = 80;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function enabledFields(Webinar $webinar): array
    {
        $customFields = $webinar->config['form']['customFields'] ?? [];
        if (!is_array($customFields)) {
            return [];
        }

        $enabled = [];
        foreach ($customFields as $field) {
            if (!is_array($field)) {
                continue;
            }
            if (array_key_exists('enabled', $field) && $field['enabled'] === false) {
                continue;
            }
            $key = $this->fieldKey($field);
            if ($key === '') {
                continue;
            }
            $enabled[] = $field;
        }

        return $enabled;
    }

    public function fieldKey(array $field): string
    {
        foreach (['key', 'name', 'id'] as $candidate) {
            if (!empty($field[$candidate]) && is_string($field[$candidate])) {
                return trim($field[$candidate]);
            }
        }

        return '';
    }

    /**
     * Validate submitted answers against the webinar's saved schema.
     *
     * @param  array<string, mixed>  $formResponses
     * @return array{answers: array<string, mixed>, snapshot: array<int, array<string, mixed>>}
     */
    public function validate(Webinar $webinar, array $formResponses): array
    {
        $fields = $this->enabledFields($webinar);
        $reserved = ['_token', 'use_wallet', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'password', 'password_confirmation', 'form_responses'];
        $known = [];
        foreach ($fields as $field) {
            $known[$this->fieldKey($field)] = $field;
        }

        $identityAliases = ['name', 'email', 'whatsapp', 'phone', 'mobile'];
        foreach ($identityAliases as $alias) {
            if (!isset($known[$alias])) {
                $known[$alias] = [
                    'key' => $alias,
                    'name' => $alias,
                    'type' => $alias === 'email' ? 'email' : ($alias === 'whatsapp' || $alias === 'phone' || $alias === 'mobile' ? 'phone' : 'text'),
                    'required' => false,
                    'label' => $alias,
                    '_identity' => true,
                ];
            }
        }

        if (count($formResponses) > self::MAX_FIELD_COUNT) {
            throw ValidationException::withMessages([
                'form_responses' => ['حجم بيانات التسجيل أكبر من المسموح.'],
            ]);
        }

        $errors = [];
        $answers = [];

        foreach ($formResponses as $submittedKey => $value) {
            if (!is_string($submittedKey) || in_array($submittedKey, $reserved, true)) {
                continue;
            }
            if (!array_key_exists($submittedKey, $known)) {
                $errors[$submittedKey][] = 'تم إرسال حقل غير موجود في نموذج هذا الويبنار.';
            }
        }

        foreach ($fields as $field) {
            $key = $this->fieldKey($field);
            $label = is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : $key;
            $type = is_string($field['type'] ?? null) ? $field['type'] : 'text';
            $isRequired = !empty($field['required']);
            $val = $formResponses[$key] ?? null;

            if ($this->isEmpty($val)) {
                if ($isRequired) {
                    $errors[$key][] = "الحقل «{$label}» مطلوب لإتمام التسجيل.";
                }
                continue;
            }

            if (is_string($val) && strlen($val) > self::MAX_ANSWER_BYTES) {
                $errors[$key][] = "قيمة الحقل «{$label}» أطول من المسموح.";
                continue;
            }

            $normalized = $this->normalizeValue($type, $val, $field, $label, $errors, $key);
            if (!array_key_exists($key, $errors)) {
                $answers[$key] = $normalized;
            }
        }

        foreach ($identityAliases as $alias) {
            if (!isset($answers[$alias]) && !$this->isEmpty($formResponses[$alias] ?? null)) {
                $answers[$alias] = is_scalar($formResponses[$alias]) ? trim((string) $formResponses[$alias]) : $formResponses[$alias];
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $snapshot = [];
        foreach ($fields as $field) {
            $key = $this->fieldKey($field);
            $snapshot[] = [
                'key' => $key,
                'id' => $field['id'] ?? $key,
                'label' => $field['label'] ?? $key,
                'type' => $field['type'] ?? 'text',
                'value' => $answers[$key] ?? null,
            ];
        }

        return [
            'answers' => $answers,
            'snapshot' => $snapshot,
        ];
    }

    protected function isEmpty(mixed $val): bool
    {
        if ($val === null) {
            return true;
        }
        if (is_string($val) && trim($val) === '') {
            return true;
        }
        if (is_array($val) && count($val) === 0) {
            return true;
        }
        if ($val === false) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, array<int, string>>  $errors
     */
    protected function normalizeValue(string $type, mixed $val, array $field, string $label, array &$errors, string $key): mixed
    {
        if ($type === 'email') {
            $email = is_string($val) ? trim($val) : '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[$key][] = "يرجى إدخال بريد إلكتروني صالح في حقل «{$label}».";
            }
            return $email;
        }

        if ($type === 'phone') {
            $phone = is_string($val) || is_numeric($val) ? preg_replace('/\s+/', '', (string) $val) : '';
            if (!preg_match('/^\+?[0-9]{8,20}$/', (string) $phone)) {
                $errors[$key][] = "يرجى إدخال رقم هاتف صالح في حقل «{$label}».";
            }
            return $phone;
        }

        if ($type === 'number') {
            if (!is_numeric($val)) {
                $errors[$key][] = "يجب أن تكون قيمة حقل «{$label}» رقماً صالحاً.";
            }
            return is_numeric($val) ? $val + 0 : $val;
        }

        if (in_array($type, ['select', 'radio'], true)) {
            $options = is_array($field['options'] ?? null) ? $field['options'] : [];
            $scalar = is_scalar($val) ? (string) $val : '';
            if ($options !== [] && !in_array($scalar, array_map('strval', $options), true)) {
                $errors[$key][] = "القيمة المحددة في «{$label}» غير صالحة.";
            }
            return $scalar;
        }

        if ($type === 'checkbox') {
            if (is_bool($val)) {
                return $val;
            }
            if (is_array($val)) {
                $options = is_array($field['options'] ?? null) ? array_map('strval', $field['options']) : [];
                foreach ($val as $item) {
                    if ($options !== [] && !in_array((string) $item, $options, true)) {
                        $errors[$key][] = "قيمة غير صالحة في حقل «{$label}».";
                        break;
                    }
                }
                return $val;
            }
            return filter_var($val, FILTER_VALIDATE_BOOLEAN);
        }

        return is_scalar($val) ? trim((string) $val) : $val;
    }
}

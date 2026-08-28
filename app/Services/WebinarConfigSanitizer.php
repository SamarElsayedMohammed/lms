<?php

declare(strict_types=1);

namespace App\Services;

class WebinarConfigSanitizer
{
    /**
     * Keep landing-page config while dropping accidental meeting secrets.
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    public function sanitizePublicConfig(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        unset($config['join_url'], $config['meeting_id'], $config['meeting_password'], $config['internalNotes'], $config['adminNotes']);

        if (!isset($config['event']['timezone']) || empty($config['event']['timezone'])) {
            $config['event']['timezone'] = 'Africa/Cairo';
        }

        return $config;
    }

    /**
     * Light admin-side structural checks for persisted JSON config.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public function validateAdminConfig(array $config): array
    {
        $errors = [];

        if (isset($config['event']['timezone']) && is_string($config['event']['timezone']) && $config['event']['timezone'] !== '') {
            try {
                new \DateTimeZone($config['event']['timezone']);
            } catch (\Throwable) {
                $errors['config.event.timezone'] = 'منطقة زمنية غير صالحة.';
            }
        }

        $fields = $config['form']['customFields'] ?? null;
        if (is_array($fields)) {
            $keys = [];
            foreach ($fields as $index => $field) {
                if (!is_array($field)) {
                    $errors["config.form.customFields.{$index}"] = 'حقل النموذج غير صالح.';
                    continue;
                }
                $key = trim((string) ($field['key'] ?? $field['name'] ?? $field['id'] ?? ''));
                if ($key === '') {
                    $errors["config.form.customFields.{$index}.key"] = 'كل حقل يحتاج معرّفاً برمجياً ثابتاً.';
                    continue;
                }
                if (isset($keys[$key])) {
                    $errors["config.form.customFields.{$index}.key"] = 'معرّف الحقل مكرر.';
                }
                $keys[$key] = true;
                $type = $field['type'] ?? 'text';
                $allowed = ['text', 'email', 'phone', 'number', 'select', 'radio', 'checkbox', 'textarea'];
                if (!in_array($type, $allowed, true)) {
                    $errors["config.form.customFields.{$index}.type"] = 'نوع الحقل غير مدعوم.';
                }
                if (in_array($type, ['select', 'radio'], true) && empty($field['options'])) {
                    $errors["config.form.customFields.{$index}.options"] = 'حقول الاختيار تحتاج قائمة خيارات.';
                }
            }
        }

        $buttons = $config['thankYou']['buttons'] ?? null;
        if (is_array($buttons)) {
            foreach ($buttons as $index => $button) {
                if (!is_array($button)) {
                    continue;
                }
                $url = $button['url'] ?? '';
                if (is_string($url) && $url !== '' && !preg_match('/^(https?:\/\/|mailto:|tel:)/i', $url)) {
                    $errors["config.thankYou.buttons.{$index}.url"] = 'رابط زر صفحة الشكر غير صالح.';
                }
            }
        }

        return $errors;
    }
}

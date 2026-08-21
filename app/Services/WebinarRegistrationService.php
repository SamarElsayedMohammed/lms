<?php

namespace App\Services;

use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Events\WebinarRegistered;

class WebinarRegistrationService
{
    protected WebinarAccessService $accessService;

    public function __construct(WebinarAccessService $accessService)
    {
        $this->accessService = $accessService;
    }

    /**
     * Register a user for a webinar with serialized row-lock concurrency and dynamic form response validation.
     *
     * @param Webinar $webinar
     * @param \App\Models\User $user
     * @param string $paymentStatus ('free', 'paid', 'pending')
     * @param float $paidAmount
     * @param \DateTimeInterface|null $expiresAt
     * @param array $formResponses
     * @param string|null $utmSource
     * @return WebinarRegistration
     * @throws Exception
     */
    public function register(
        Webinar $webinar,
        \App\Models\User $user,
        string $paymentStatus = 'free',
        float $paidAmount = 0.00,
        ?\DateTimeInterface $expiresAt = null,
        array $formResponses = [],
        ?string $utmSource = null
    ): WebinarRegistration {
        // Pre-transaction preliminary validation
        $check = $this->accessService->canRegister($webinar, $user);
        if (!$check['allowed']) {
            throw new Exception($check['reason'], $check['code']);
        }

        // Authoritative server-side validation against webinar's dynamic form schema
        $this->validateCustomFields($webinar, $formResponses);

        // Set default 1 hour expiry for pending payments if not specified
        if ($paymentStatus === 'pending' && $expiresAt === null) {
            $expiresAt = now()->addHour();
        } elseif ($paymentStatus !== 'pending') {
            $expiresAt = null;
        }

        $registration = DB::transaction(function () use ($webinar, $user, $paymentStatus, $paidAmount, $expiresAt, $formResponses, $utmSource) {
            // Lock the webinar row to serialize all concurrent registration attempts
            $lockedWebinar = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();

            if (!$lockedWebinar->is_published) {
                throw new Exception('Webinar not found or unpublished.', 404);
            }

            if ($lockedWebinar->status === 'completed' || $lockedWebinar->status === 'cancelled') {
                throw new Exception('This webinar is no longer available for registration.', 400);
            }

            if ($lockedWebinar->start_at && $lockedWebinar->start_at->isPast()) {
                throw new Exception('Registration is closed because this webinar has already started.', 400);
            }

            $activeCount = $lockedWebinar->activeRegistrationsCount();
            if ($lockedWebinar->max_attendees > 0 && $activeCount >= $lockedWebinar->max_attendees) {
                throw new Exception('This webinar is full. No more registrations allowed.', 409);
            }

            // Check if user already has a registration record
            $existing = WebinarRegistration::where('user_id', $user->id)
                ->where('webinar_id', $lockedWebinar->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->isConfirmed()) {
                    throw new Exception('You are already registered for this webinar.', 409);
                }

                if ($existing->isPending() && !$existing->isExpired()) {
                    if ($paymentStatus === 'pending') {
                        return $existing;
                    }
                    // Transition active pending to paid/free
                    $existing->update([
                        'payment_status' => $paymentStatus,
                        'paid_amount' => $paidAmount,
                        'expires_at' => $expiresAt,
                        'form_responses' => !empty($formResponses) ? $formResponses : $existing->form_responses,
                        'utm_source' => $utmSource ?? $existing->utm_source,
                    ]);
                    return $existing;
                }

                // If existing record was expired pending, renew it
                $existing->update([
                    'payment_status' => $paymentStatus,
                    'paid_amount' => $paidAmount,
                    'expires_at' => $expiresAt,
                    'form_responses' => !empty($formResponses) ? $formResponses : $existing->form_responses,
                    'utm_source' => $utmSource ?? $existing->utm_source,
                ]);
                return $existing;
            }

            try {
                return WebinarRegistration::create([
                    'user_id' => $user->id,
                    'webinar_id' => $lockedWebinar->id,
                    'payment_status' => $paymentStatus,
                    'paid_amount' => $paidAmount,
                    'expires_at' => $expiresAt,
                    'form_responses' => !empty($formResponses) ? $formResponses : null,
                    'utm_source' => $utmSource,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ((string) $e->getCode() === '23000') {
                    throw new Exception('You are already registered for this webinar.', 409);
                }
                throw $e;
            }
        });

        // Dispatch confirmation event only when registration is confirmed (not pending)
        if ($registration->isConfirmed() && class_exists(WebinarRegistered::class)) {
            event(new WebinarRegistered($registration));
        }

        return $registration;
    }

    /**
     * Validate submitted responses against authoritative custom fields defined in webinar config.
     */
    protected function validateCustomFields(Webinar $webinar, array $formResponses): void
    {
        $customFields = $webinar->config['form']['customFields'] ?? [];
        if (!is_array($customFields) || empty($customFields)) {
            return;
        }

        $errors = [];

        foreach ($customFields as $field) {
            if (!is_array($field) || empty($field['name'])) {
                continue;
            }

            $key = $field['name'];
            $label = $field['label'] ?? $key;
            $isRequired = !empty($field['required']);
            $type = $field['type'] ?? 'text';
            $val = $formResponses[$key] ?? null;

            if ($isRequired && ($val === null || $val === '')) {
                $errors[$key][] = "الحقل '{$label}' مطلوب لإتمام التسجيل.";
                continue;
            }

            if ($val !== null && $val !== '') {
                if ($type === 'email' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    $errors[$key][] = "يرجى إدخال بريد إلكتروني صالح في حقل '{$label}'.";
                }
                if ($type === 'number' && !is_numeric($val)) {
                    $errors[$key][] = "يجب أن تكون قيمة حقل '{$label}' رقماً صالحاً.";
                }
                if (in_array($type, ['select', 'radio']) && !empty($field['options']) && is_array($field['options'])) {
                    if (!in_array($val, $field['options'], true)) {
                        $errors[$key][] = "القيمة المحددة في '{$label}' غير صالحة.";
                    }
                }
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}

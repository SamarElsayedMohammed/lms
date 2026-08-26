<?php

namespace App\Services;

use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Events\WebinarRegistered;

class WebinarRegistrationService
{
    protected WebinarAccessService $accessService;
    protected WebinarFormSchemaValidator $formValidator;

    public function __construct(WebinarAccessService $accessService, WebinarFormSchemaValidator $formValidator)
    {
        $this->accessService = $accessService;
        $this->formValidator = $formValidator;
    }

    /**
     * Resolve the registrant account. Authenticated users win.
     * Free-webinar guests are matched or created by email so later join/notifications keep working.
     */
    public function resolveRegistrant(?User $authUser, array $formResponses): User
    {
        if ($authUser) {
            return $authUser;
        }

        $email = $this->extractScalar($formResponses, ['email']);
        $phone = $this->extractScalar($formResponses, ['whatsapp', 'phone', 'mobile']);
        $name = $this->extractScalar($formResponses, ['name']) ?: 'مشارك';

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $existing = User::query()->where('email', $email)->first();
            if ($existing) {
                return $existing;
            }
        } elseif ($phone) {
            $normalizedPhone = preg_replace('/\D+/', '', $phone) ?: $phone;
            $email = 'guest+' . $normalizedPhone . '@webinar.skillso.local';
            $existing = User::query()
                ->where('mobile', $phone)
                ->orWhere('email', $email)
                ->first();
            if ($existing) {
                return $existing;
            }
        } else {
            throw ValidationException::withMessages([
                'email' => ['البريد الإلكتروني أو رقم الهاتف مطلوب لإتمام التسجيل.'],
            ]);
        }

        $slugBase = Str::slug($name);
        if ($slugBase === '') {
            $slugBase = 'guest';
        }

        return User::create([
            'name' => $name,
            'email' => $email,
            'mobile' => $phone,
            'password' => Hash::make(Str::random(40)),
            'slug' => $slugBase . '-' . Str::random(8),
            'is_active' => true,
            'type' => 'email',
            'is_webinar_guest' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $formResponses
     * @param  array<int, string>  $keys
     */
    protected function extractScalar(array $formResponses, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $formResponses[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
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
            throw new WebinarRegistrationDeniedException(
                (string) $check['reason'],
                (int) $check['code'],
                (string) ($check['error_code'] ?? 'bad_request'),
            );
        }

        $validatedForm = $this->formValidator->validate($webinar, $formResponses);
        $formResponses = array_merge($validatedForm['answers'], [
            '_schema' => $validatedForm['snapshot'],
        ]);

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

}

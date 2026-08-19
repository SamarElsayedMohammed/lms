<?php

namespace App\Services;

use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistration;

class WebinarAccessService
{
    /**
     * Determine if a user/visitor is allowed to view the webinar marketing/details page.
     */
    public function canViewWebinar(Webinar $webinar, ?User $user = null): bool
    {
        if ($webinar->is_published) {
            return true;
        }

        if (!$user) {
            return false;
        }

        // Admin or assigned instructor can preview unpublished webinars
        if ($user->hasRole('Super Admin') || $user->hasRole('Supervisor') || $user->hasRole('Staff')) {
            return true;
        }

        if ($user->hasRole('Instructor') && (int) $webinar->instructor_id === (int) $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Get the user's registration for a webinar if one exists.
     */
    public function getRegistration(Webinar $webinar, ?User $user = null): ?WebinarRegistration
    {
        if (!$user) {
            return null;
        }

        return WebinarRegistration::where('user_id', $user->id)
            ->where('webinar_id', $webinar->id)
            ->first();
    }

    /**
     * Determine if the user has confirmed entitlement to the webinar.
     * PENDING registrations are NEVER entitled.
     */
    public function isUserEntitled(Webinar $webinar, ?User $user = null): bool
    {
        if (!$user) {
            return false;
        }

        $registration = $this->getRegistration($webinar, $user);
        if (!$registration) {
            return false;
        }

        if ($webinar->is_free) {
            return in_array($registration->payment_status, ['free', 'paid'], true);
        }

        return $registration->payment_status === 'paid';
    }

    /**
     * Check if a user can register for the given webinar.
     * Returns an array with [allowed, reason, code].
     */
    public function canRegister(Webinar $webinar, ?User $user = null): array
    {
        if (!$webinar->is_published) {
            return [
                'allowed' => false,
                'reason' => 'Webinar not found or unpublished.',
                'error_code' => 'webinar_not_published',
                'code' => 404,
            ];
        }

        if ($webinar->status === 'cancelled') {
            return [
                'allowed' => false,
                'reason' => 'This webinar has been cancelled.',
                'error_code' => 'webinar_cancelled',
                'code' => 400,
            ];
        }

        if ($webinar->status === 'completed') {
            return [
                'allowed' => false,
                'reason' => 'This webinar has already ended.',
                'error_code' => 'webinar_completed',
                'code' => 400,
            ];
        }

        // Registration window enforcement: cannot register after start_at has passed
        if ($webinar->start_at && $webinar->start_at->isPast()) {
            return [
                'allowed' => false,
                'reason' => 'Registration is closed because this webinar has already started.',
                'error_code' => 'registration_closed_webinar_started',
                'code' => 400,
            ];
        }

        if ($user) {
            $registration = $this->getRegistration($webinar, $user);
            if ($registration) {
                // If confirmed or active (unexpired) pending, reject duplicate
                if ($registration->isConfirmed() || ($registration->isPending() && !$registration->isExpired())) {
                    return [
                        'allowed' => false,
                        'reason' => 'You are already registered for this webinar.',
                        'error_code' => 'already_registered',
                        'code' => 409,
                    ];
                }
            }
        }

        if ($webinar->is_full) {
            return [
                'allowed' => false,
                'reason' => 'This webinar is full. No more registrations allowed.',
                'error_code' => 'webinar_is_full',
                'code' => 409,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'error_code' => null,
            'code' => 200,
        ];
    }

    /**
     * Authoritative check for joining the live stream.
     */
    public function canJoinLive(Webinar $webinar, ?User $user = null): array
    {
        if (!$user) {
            return [
                'allowed' => false,
                'reason' => 'Unauthenticated',
                'error_code' => 'unauthenticated',
                'code' => 401,
            ];
        }

        if (!$this->canViewWebinar($webinar, $user)) {
            return [
                'allowed' => false,
                'reason' => 'Webinar not found',
                'error_code' => 'webinar_not_found',
                'code' => 404,
            ];
        }

        if ($webinar->status === 'cancelled') {
            return [
                'allowed' => false,
                'reason' => 'This webinar was cancelled.',
                'error_code' => 'webinar_cancelled',
                'code' => 403,
            ];
        }

        if ($webinar->status === 'completed') {
            return [
                'allowed' => false,
                'reason' => 'This webinar has already ended.',
                'error_code' => 'webinar_completed',
                'code' => 403,
            ];
        }

        $isHostOrStaff = $this->isHostOrStaff($webinar, $user);

        if (!$isHostOrStaff && !$this->isUserEntitled($webinar, $user)) {
            return [
                'allowed' => false,
                'reason' => 'You must complete registration and payment for this webinar first.',
                'error_code' => 'payment_required_or_not_registered',
                'code' => 403,
            ];
        }

        // Hosts/staff may open the room early; attendees join from 15 minutes before start.
        if (
            !$isHostOrStaff
            && $webinar->status === 'scheduled'
            && $webinar->start_at
            && $webinar->start_at->gt(now()->addMinutes(15))
        ) {
            return [
                'allowed' => false,
                'reason' => 'The webinar session is not open yet. Please check back 15 minutes before the start time.',
                'error_code' => 'join_too_early',
                'code' => 400,
            ];
        }

        $joinUrl = $webinar->join_url;
        if (empty($joinUrl) && $webinar->provider === 'jitsi') {
            $joinUrl = "https://meet.jit.si/" . $webinar->slug;
        }

        return [
            'allowed' => true,
            'reason' => null,
            'error_code' => null,
            'code' => 200,
            'data' => [
                'join_url' => $joinUrl,
                'meeting_id' => $webinar->meeting_id,
                'meeting_password' => $webinar->meeting_password,
                'provider' => $webinar->provider,
            ],
        ];
    }

    /**
     * Authoritative check for accessing the recording.
     */
    public function canViewRecording(Webinar $webinar, ?User $user = null): array
    {
        if (!$user) {
            return [
                'allowed' => false,
                'reason' => 'Unauthenticated',
                'error_code' => 'unauthenticated',
                'code' => 401,
            ];
        }

        if ($webinar->status !== 'completed' || empty($webinar->recording_url)) {
            return [
                'allowed' => false,
                'reason' => 'Recording is not available for this webinar.',
                'error_code' => 'recording_not_available',
                'code' => 404,
            ];
        }

        if (!$this->isHostOrStaff($webinar, $user) && !$this->isUserEntitled($webinar, $user)) {
            return [
                'allowed' => false,
                'reason' => 'You do not have access to this recording.',
                'error_code' => 'unauthorized_recording_access',
                'code' => 403,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'error_code' => null,
            'code' => 200,
            'recording_url' => $webinar->recording_url,
        ];
    }

    /**
     * Sanitize the webinar model attributes for public/user responses.
     * Prevents any credential or PII leaks.
     */
    public function sanitizeWebinarForResponse(Webinar $webinar, ?User $user = null): Webinar
    {
        // Never expose raw registrations collection to public response
        $webinar->makeHidden(['registrations']);

        $entitled = $this->isUserEntitled($webinar, $user) || $this->isHostOrStaff($webinar, $user);

        // Live credentials are only returned from GET /webinars/{slug}/join.
        $webinar->makeHidden([
            'join_url',
            'meeting_id',
            'meeting_password',
        ]);

        if (!$entitled || $webinar->status !== 'completed' || empty($webinar->recording_url)) {
            $webinar->makeHidden(['recording_url']);
        }

        return $webinar;
    }

    public function isHostOrStaff(Webinar $webinar, ?User $user = null): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasRole('Super Admin') || $user->hasRole('Supervisor') || $user->hasRole('Staff')) {
            return true;
        }

        return $user->hasRole('Instructor') && (int) $webinar->instructor_id === (int) $user->id;
    }
}

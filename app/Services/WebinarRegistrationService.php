<?php

namespace App\Services;

use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Exception;
use Illuminate\Support\Facades\DB;
use App\Events\WebinarRegistered;

class WebinarRegistrationService
{
    /**
     * Register a user for a webinar.
     *
     * @param Webinar $webinar
     * @param \App\Models\User $user
     * @param string $paymentStatus
     * @param float $paidAmount
     * @return WebinarRegistration
     * @throws Exception
     */
    public function register(Webinar $webinar, \App\Models\User $user, string $paymentStatus = 'free', float $paidAmount = 0.00)
    {
        if ($webinar->status === 'completed' || $webinar->status === 'cancelled') {
            throw new Exception('This webinar is no longer available for registration.', 400);
        }

        $registration = DB::transaction(function () use ($webinar, $user, $paymentStatus, $paidAmount) {
            // Lock the webinar row first; locking an aggregate count does not protect
            // the range from concurrent inserts.
            $lockedWebinar = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
            if ($lockedWebinar->status === 'completed' || $lockedWebinar->status === 'cancelled') {
                throw new Exception('This webinar is no longer available for registration.', 400);
            }
            if ($lockedWebinar->max_attendees > 0 &&
                WebinarRegistration::where('webinar_id', $lockedWebinar->id)->count() >= $lockedWebinar->max_attendees) {
                throw new Exception('webinar_is_full', 409);
            }
            if (WebinarRegistration::where('user_id', $user->id)->where('webinar_id', $lockedWebinar->id)->exists()) {
                throw new Exception('already_registered', 409);
            }

            $registration = WebinarRegistration::create([
                'user_id' => $user->id,
                'webinar_id' => $lockedWebinar->id,
                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
            ]);

            // If registrations_count is a column, increment it:
            // $webinar->increment('registrations_count');

            return $registration;
        });

        // Dispatch after the transaction commits so queued listeners never observe
        // an uncommitted registration.
        if (class_exists(WebinarRegistered::class)) {
            event(new WebinarRegistered($registration));
        }
        return $registration;
    }
}

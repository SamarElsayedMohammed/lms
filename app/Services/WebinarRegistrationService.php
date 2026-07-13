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

        // Capacity check
        // "if (count >= max_attendees) abort(409)"
        if ($webinar->max_attendees > 0) {
            // Lock the row to prevent race conditions
            $currentCount = WebinarRegistration::where('webinar_id', $webinar->id)->lockForUpdate()->count();
            if ($currentCount >= $webinar->max_attendees) {
                throw new Exception('webinar_is_full', 409);
            }
        }

        // Duplication check
        $exists = WebinarRegistration::where('user_id', $user->id)
            ->where('webinar_id', $webinar->id)
            ->exists();

        if ($exists) {
            throw new Exception('already_registered', 409);
        }

        return DB::transaction(function () use ($webinar, $user, $paymentStatus, $paidAmount) {
            $registration = WebinarRegistration::create([
                'user_id' => $user->id,
                'webinar_id' => $webinar->id,
                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
            ]);

            // If registrations_count is a column, increment it:
            // $webinar->increment('registrations_count');

            // Fire event
            if (class_exists(WebinarRegistered::class)) {
                event(new WebinarRegistered($registration));
            }

            return $registration;
        });
    }
}

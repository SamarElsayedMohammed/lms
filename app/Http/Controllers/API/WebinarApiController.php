<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class WebinarApiController extends Controller
{
    /**
     * List all scheduled/live webinars
     */
    public function index(Request $request)
    {
        try {
            $query = Webinar::with('instructor:id,name,profile')
                ->whereIn('status', ['scheduled', 'live'])
                ->where('start_at', '>=', now()->subHours(2));

            $perPage = min((int) $request->input('per_page', 15), 50);
            $webinars = $query->orderBy('start_at', 'asc')->paginate($perPage);

            return ApiResponseService::successResponse('Webinars retrieved successfully', $webinars);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve webinars: ' . $e->getMessage());
        }
    }

    /**
     * Get webinar details
     */
    public function show($id)
    {
        try {
            $webinar = Webinar::with(['instructor:id,name,profile', 'registrations'])
                ->where('id', $id)
                ->first();

            if (!$webinar) {
                return ApiResponseService::errorResponse('Webinar not found', [], 404);
            }

            $user = Auth::guard('sanctum')->user();
            $is_registered = $user ? WebinarRegistration::where('user_id', $user->id)->where('webinar_id', $webinar->id)->exists() : false;

            return ApiResponseService::successResponse('Webinar details retrieved', [
                'webinar' => $webinar,
                'is_registered' => $is_registered
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve webinar: ' . $e->getMessage());
        }
    }

    /**
     * Register for a webinar
     */
    public function register(Request $request, $id)
    {
        try {
            $user = Auth::user();
            $webinar = Webinar::findOrFail($id);

            if ($webinar->status === 'completed' || $webinar->status === 'cancelled') {
                return ApiResponseService::errorResponse('This webinar is no longer available for registration.');
            }

            $existing = WebinarRegistration::where('user_id', $user->id)->where('webinar_id', $webinar->id)->first();
            if ($existing) {
                return ApiResponseService::successResponse('You are already registered for this webinar.');
            }

            if (!$webinar->is_free && $webinar->price > 0) {
                // Here we would integrate payment logic or check wallet balance
                // For now, let's assume it requires manual approval or redirect to payment
                return ApiResponseService::successResponse('This webinar requires payment.', ['requires_payment' => true, 'price' => $webinar->price]);
            }

            WebinarRegistration::create([
                'user_id' => $user->id,
                'webinar_id' => $webinar->id,
                'payment_status' => $webinar->is_free ? 'free' : 'pending',
            ]);

            return ApiResponseService::successResponse('Successfully registered for the webinar.');
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to register: ' . $e->getMessage());
        }
    }

    /**
     * Join a webinar (get join URL)
     */
    public function join($id)
    {
        try {
            $user = Auth::user();
            $webinar = Webinar::findOrFail($id);

            $registration = WebinarRegistration::where('user_id', $user->id)
                ->where('webinar_id', $webinar->id)
                ->first();

            if (!$registration && !$webinar->is_free) {
                return ApiResponseService::errorResponse('You must register for this webinar first.');
            }

            if ($webinar->status === 'scheduled' && $webinar->start_at->gt(now()->addMinutes(15))) {
                return ApiResponseService::errorResponse('The webinar has not started yet. You can join 15 minutes before the start time.');
            }

            // Logic to generate provider URL if needed (e.g. for Jitsi)
            $join_url = $webinar->join_url;
            if ($webinar->provider === 'jitsi' && empty($join_url)) {
                $join_url = "https://meet.jit.si/" . $webinar->slug;
            }

            return ApiResponseService::successResponse('Join link generated', [
                'join_url' => $join_url,
                'meeting_id' => $webinar->meeting_id,
                'meeting_password' => $webinar->meeting_password
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to join webinar: ' . $e->getMessage());
        }
    }
}

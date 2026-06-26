<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Services\ApiResponseService;
use App\Services\FirebaseConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

final class FirebaseSettingsAdminApiController extends AdminCrudApiController
{
    public function __construct(
        private readonly FirebaseConfigService $firebaseConfigService,
    ) {
        $this->middleware('auth:sanctum');
    }

    public function show()
    {
        $this->ensureFirebaseAccess('settings-firebase-list');

        return ApiResponseService::successResponse(
            'Firebase settings retrieved successfully',
            $this->firebaseConfigService->getAdminSettingsPayload(),
        );
    }

    public function update(Request $request)
    {
        $this->ensureFirebaseAccess('settings-firebase-edit');

        $validator = Validator::make($request->all(), [
            'firebase_api_key' => 'nullable|string|max:255',
            'firebase_auth_domain' => 'nullable|string|max:255',
            'firebase_project_id' => 'nullable|string|max:255',
            'firebase_storage_bucket' => 'nullable|string|max:255',
            'firebase_messaging_sender_id' => 'nullable|string|max:255',
            'firebase_app_id' => 'nullable|string|max:255',
            'firebase_measurement_id' => 'nullable|string|max:255',
            'firebase_service_file' => 'nullable|file|mimes:json|max:2048',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $payload = $this->firebaseConfigService->syncAdminSettings(
                $this->extractClientSettings($request),
                $request->file('firebase_service_file'),
            );

            return ApiResponseService::successResponse('Firebase settings updated successfully', $payload);
        } catch (InvalidArgumentException $e) {
            return ApiResponseService::validationError($e->getMessage());
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            return ApiResponseService::errorResponse(exception: $e);
        }
    }

    private function ensureFirebaseAccess(string $permission): void
    {
        $this->ensureAdmin();

        if (!Auth::user()?->can($permission)) {
            $this->unauthorized(__('You do not have permission to perform this action'));
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function extractClientSettings(Request $request): array
    {
        $settings = [];

        foreach ($this->firebaseConfigService->clientSettingKeys() as $key) {
            if ($request->exists($key)) {
                $settings[$key] = $request->input($key);
            }
        }

        return $settings;
    }
}

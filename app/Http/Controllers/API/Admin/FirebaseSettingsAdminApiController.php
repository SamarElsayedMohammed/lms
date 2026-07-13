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

        $payload = $this->firebaseConfigService->getAdminSettingsPayload();
        
        // Flatten client settings to root so frontend extractFirebaseFieldsFromRecord finds them
        $flatPayload = array_merge($payload, $payload['client_settings'] ?? []);

        return ApiResponseService::successResponse(
            'Firebase settings retrieved successfully',
            $flatPayload,
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
            'project_id' => 'nullable|string|max:255',
            'fcm_server_key' => 'nullable|string|max:255',
            'service_account_json' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $clientSettings = $this->extractClientSettings($request);
            
            // Map frontend keys to backend expected keys
            if ($request->filled('project_id')) {
                $clientSettings['firebase_project_id'] = $request->input('project_id');
            }
            if ($request->filled('fcm_server_key')) {
                $clientSettings['firebase_fcm_server_key'] = $request->input('fcm_server_key');
            }

            $serviceFile = $request->file('firebase_service_file');

            // Handle direct JSON string upload from frontend
            if (!$serviceFile && $request->filled('service_account_json')) {
                $jsonContent = $request->input('service_account_json');
                $tempPath = sys_get_temp_dir() . '/' . uniqid('firebase_', true) . '.json';
                file_put_contents($tempPath, $jsonContent);
                $serviceFile = new \Illuminate\Http\UploadedFile(
                    $tempPath,
                    'firebase-credentials.json',
                    'application/json',
                    null,
                    true
                );
            }

            $payload = $this->firebaseConfigService->syncAdminSettings(
                $clientSettings,
                $serviceFile,
            );

            $flatPayload = array_merge($payload, $payload['client_settings'] ?? []);

            // Clean up temp file
            if (isset($tempPath) && file_exists($tempPath)) {
                @unlink($tempPath);
            }

            return ApiResponseService::successResponse('Firebase settings updated successfully', $flatPayload);
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

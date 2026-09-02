<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Services\FileService;
use App\Services\HelperService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class FirebaseConfigService
{
    /** @var list<string> */
    private const array REQUIRED_CLIENT_KEYS = [
        'firebase_api_key',
        'firebase_auth_domain',
        'firebase_project_id',
        'firebase_app_id',
    ];

    /** Server-only settings persisted in admin but never returned on public client config. */
    private const array SERVER_ONLY_SETTING_KEYS = [
        'firebase_fcm_server_key',
    ];

    private const array CLIENT_KEY_MAP = [
        'firebase_api_key' => 'apiKey',
        'firebase_auth_domain' => 'authDomain',
        'firebase_project_id' => 'projectId',
        'firebase_storage_bucket' => 'storageBucket',
        'firebase_messaging_sender_id' => 'messagingSenderId',
        'firebase_app_id' => 'appId',
        'firebase_measurement_id' => 'measurementId',
        'firebase_fcm_server_key' => 'fcmServerKey',
    ];

    /**
     * @return array<string, string>
     */
    public function getClientSettingsRaw(): array
    {
        return HelperService::systemSettings(array_keys(self::CLIENT_KEY_MAP));
    }

    /**
     * @return array<string, string>
     */
    public function getClientConfig(): array
    {
        $raw = $this->getClientSettingsRaw();
        $config = [];

        foreach (self::CLIENT_KEY_MAP as $settingKey => $sdkKey) {
            if (in_array($settingKey, self::SERVER_ONLY_SETTING_KEYS, true)) {
                continue;
            }

            $value = trim((string) ($raw[$settingKey] ?? ''));
            if ($value !== '') {
                $config[$sdkKey] = $value;
            }
        }

        return $config;
    }

    public function isClientConfigComplete(): bool
    {
        $raw = $this->getClientSettingsRaw();

        foreach (self::REQUIRED_CLIENT_KEYS as $key) {
            if (trim((string) ($raw[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function getMissingClientKeys(): array
    {
        $raw = $this->getClientSettingsRaw();
        $missing = [];

        foreach (self::REQUIRED_CLIENT_KEYS as $key) {
            if (trim((string) ($raw[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public function getCredentialsPath(): ?string
    {
        $storedPath = HelperService::systemSettings('firebase_service_file');

        if (is_string($storedPath) && $storedPath !== '') {
            foreach ($this->credentialPathCandidates($storedPath) as $candidate) {
                if (is_readable($candidate)) {
                    return $candidate;
                }
            }
        }

        $envPath = config('firebase.projects.app.credentials');

        $candidates = [];
        if (is_string($envPath) && $envPath !== '') {
            $candidates = array_merge($candidates, $this->credentialPathCandidates($envPath));
        }

        // Add standard candidate locations
        $candidates[] = base_path('firebase_credentials.json');
        $candidates[] = base_path('../firebase_credentials.json');
        $candidates[] = storage_path('app/firebase/firebase_credentials.json');
        $candidates[] = storage_path('app/firebase/firebase_service.json');

        foreach (array_unique($candidates) as $candidate) {
            if (is_string($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function isServerConfigured(): bool
    {
        return $this->getCredentialsPath() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getServiceAccountData(): array
    {
        $path = $this->getCredentialsPath();

        if ($path === null) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function projectIdsMatch(): bool
    {
        $settingsProjectId = trim((string) (HelperService::systemSettings('firebase_project_id') ?? ''));
        $serviceAccount = $this->getServiceAccountData();
        $credentialsProjectId = trim((string) ($serviceAccount['project_id'] ?? ''));

        if ($settingsProjectId === '' || $credentialsProjectId === '') {
            return false;
        }

        return strcasecmp($settingsProjectId, $credentialsProjectId) === 0;
    }

    public function isFcmReady(): bool
    {
        return $this->isServerConfigured()
            && trim((string) (HelperService::systemSettings('firebase_project_id') ?? '')) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealthReport(): array
    {
        $credentialsPath = $this->getCredentialsPath();

        return [
            'client_config_complete' => $this->isClientConfigComplete(),
            'server_credentials_present' => $credentialsPath !== null,
            'project_id_match' => $this->projectIdsMatch(),
            'fcm_ready' => $this->isFcmReady(),
            'missing_client_keys' => $this->getMissingClientKeys(),
            'credentials_path' => $credentialsPath,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{project_id: string|null, error: string|null}
     */
    public function parseServiceAccountFile(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            return ['project_id' => null, 'error' => 'Unable to read uploaded file'];
        }

        return $this->parseServiceAccountJson($contents);
    }

    /**
     * @return array{project_id: string|null, error: string|null}
     */
    public function parseServiceAccountJson(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return ['project_id' => null, 'error' => 'Invalid JSON file'];
        }

        if (empty($decoded['private_key']) || empty($decoded['client_email'])) {
            return ['project_id' => null, 'error' => 'File is not a valid Firebase service account JSON'];
        }

        $projectId = trim((string) ($decoded['project_id'] ?? ''));

        if ($projectId === '') {
            return ['project_id' => null, 'error' => 'Service account JSON is missing project_id'];
        }

        return ['project_id' => $projectId, 'error' => null];
    }

    /**
     * @return list<string>
     */
    public function clientSettingKeys(): array
    {
        return array_keys(self::CLIENT_KEY_MAP);
    }

    /**
     * @param array<string, string|null> $clientSettings
     * @return array<string, mixed>
     */
    public function syncAdminSettings(array $clientSettings, null|UploadedFile $serviceFile = null): array
    {
        if ($clientSettings !== []) {
            $this->persistClientSettings(array_map(
                static fn ($value) => (string) ($value ?? ''),
                $clientSettings,
            ));
        }

        if ($serviceFile !== null) {
            $parsed = $this->parseServiceAccountFile($serviceFile);

            if ($parsed['error'] !== null) {
                throw new InvalidArgumentException($parsed['error']);
            }

            $existingPath = HelperService::systemSettings('firebase_service_file');
            $path = FileService::replace($serviceFile, 'firebase', $existingPath);
            $settingArray = [
                ['name' => 'firebase_service_file', 'value' => $path, 'type' => 'file'],
            ];

            if (!empty($parsed['project_id'])) {
                $settingArray[] = [
                    'name' => 'firebase_project_id',
                    'value' => $parsed['project_id'],
                    'type' => 'string',
                ];
            }

            Setting::upsert($settingArray, ['name']);
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));

            HelperService::changeEnv([
                'FIREBASE_CREDENTIALS' => $path,
            ]);
        }

        return $this->getAdminSettingsPayload();
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdminSettingsPayload(): array
    {
        $raw = $this->getClientSettingsRaw();

        return [
            'client_settings' => $raw,
            'client_config_preview' => $this->getClientConfig(),
            'service_account_uploaded' => $this->isServerConfigured(),
            'health' => $this->getHealthReport(),
        ];
    }

    /**
     * @param array<string, string> $clientSettings
     */
    public function persistClientSettings(array $clientSettings): void
    {
        $settingArray = [];

        foreach (self::CLIENT_KEY_MAP as $settingKey => $_sdkKey) {
            if (!array_key_exists($settingKey, $clientSettings)) {
                continue;
            }

            $settingArray[] = [
                'name' => $settingKey,
                'value' => trim((string) $clientSettings[$settingKey]),
                'type' => 'string',
            ];
        }

        if ($settingArray !== []) {
            Setting::upsert($settingArray, ['name']);
            CachingService::removeCache(config('constants.CACHE.SETTINGS'));
        }
    }

    /**
     * @return list<string>
     */
    private function credentialPathCandidates(string $path): array
    {
        $normalized = ltrim($path, '/');

        return array_values(array_unique(array_filter([
            $path,
            base_path($path),
            storage_path('app/' . $normalized),
            storage_path('app/public/' . $normalized),
            public_path($normalized),
            Storage::disk('public')->path($normalized),
            Storage::path($normalized),
        ])));
    }
}

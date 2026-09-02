<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Setting;
use App\Services\CachingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FirebaseConfigApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CachingService::removeCache(config('constants.CACHE.SETTINGS'));
    }

    public function test_firebase_config_returns_not_configured_when_incomplete(): void
    {
        $response = $this->getJson('/api/firebase-config');

        $response->assertOk()
            ->assertJsonPath('error', false)
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.config', null);
    }

    public function test_firebase_config_returns_client_config_when_complete(): void
    {
        foreach ([
            'firebase_api_key' => 'test-api-key',
            'firebase_auth_domain' => 'demo.firebaseapp.com',
            'firebase_project_id' => 'demo-project',
            'firebase_app_id' => '1:123:web:abc',
            'firebase_fcm_server_key' => 'server-only-fcm-key',
        ] as $name => $value) {
            Setting::updateOrCreate(['name' => $name], ['value' => $value, 'type' => 'string']);
        }
        CachingService::removeCache(config('constants.CACHE.SETTINGS'));

        $response = $this->getJson('/api/firebase-config');

        $response->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.config.apiKey', 'test-api-key')
            ->assertJsonPath('data.config.projectId', 'demo-project')
            ->assertJsonMissingPath('data.config.fcmServerKey');
    }

    public function test_admin_firebase_settings_requires_authentication(): void
    {
        $this->getJson('/api/admin/settings/firebase')->assertUnauthorized();
    }
}

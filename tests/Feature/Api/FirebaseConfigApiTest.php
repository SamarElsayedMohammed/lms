<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FirebaseConfigApiTest extends TestCase
{
    use RefreshDatabase;

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
        ] as $name => $value) {
            Setting::create(['name' => $name, 'value' => $value, 'type' => 'string']);
        }

        $response = $this->getJson('/api/firebase-config');

        $response->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.config.apiKey', 'test-api-key')
            ->assertJsonPath('data.config.projectId', 'demo-project');
    }

    public function test_admin_firebase_settings_requires_authentication(): void
    {
        $this->getJson('/api/admin/settings/firebase')->assertUnauthorized();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Services\FirebaseConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FirebaseConfigServiceTest extends TestCase
{
    use RefreshDatabase;

    private FirebaseConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FirebaseConfigService::class);
    }

    public function test_client_config_is_incomplete_when_required_keys_missing(): void
    {
        Setting::create(['name' => 'firebase_api_key', 'value' => 'abc', 'type' => 'string']);

        $this->assertFalse($this->service->isClientConfigComplete());
        $this->assertContains('firebase_project_id', $this->service->getMissingClientKeys());
    }

    public function test_client_config_is_complete_when_required_keys_present(): void
    {
        foreach ([
            'firebase_api_key' => 'abc',
            'firebase_auth_domain' => 'demo.firebaseapp.com',
            'firebase_project_id' => 'demo-project',
            'firebase_app_id' => '1:123:web:abc',
        ] as $name => $value) {
            Setting::create(['name' => $name, 'value' => $value, 'type' => 'string']);
        }

        $this->assertTrue($this->service->isClientConfigComplete());
        $config = $this->service->getClientConfig();
        $this->assertSame('abc', $config['apiKey']);
        $this->assertSame('demo-project', $config['projectId']);
    }

    public function test_parse_service_account_json_extracts_project_id(): void
    {
        $json = json_encode([
            'project_id' => 'skillso-demo',
            'private_key' => '-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n',
            'client_email' => 'firebase-adminsdk@test.iam.gserviceaccount.com',
        ]);

        $parsed = $this->service->parseServiceAccountJson($json);

        $this->assertSame('skillso-demo', $parsed['project_id']);
        $this->assertNull($parsed['error']);
    }

    public function test_parse_service_account_json_rejects_invalid_file(): void
    {
        $parsed = $this->service->parseServiceAccountJson('{"foo":"bar"}');

        $this->assertNull($parsed['project_id']);
        $this->assertNotNull($parsed['error']);
    }
}

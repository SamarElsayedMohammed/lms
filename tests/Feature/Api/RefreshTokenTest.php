<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_token_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/api/refresh-token');

        $response->assertStatus(401);
    }

    public function test_access_token_cannot_be_used_to_refresh(): void
    {
        $user = User::factory()->create();

        $access = $user->createToken('access-test', ['*'], now()->addHour());
        $access->accessToken->token_type = 'access';
        $access->accessToken->save();

        $response = $this->withToken($access->plainTextToken)
            ->postJson('/api/refresh-token');

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid token type. A refresh_token is required.');
    }

    public function test_valid_refresh_token_returns_new_token_pair(): void
    {
        $user = User::factory()->create();

        $refresh = $user->createToken('refresh-test', ['*'], now()->addDays(30));
        $refresh->accessToken->token_type = 'refresh';
        $refresh->accessToken->save();

        $response = $this->withToken($refresh->plainTextToken)
            ->postJson('/api/refresh-token', [
                'refresh_token' => $refresh->plainTextToken,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['token', 'refresh_token', 'expires_in']);
    }
}

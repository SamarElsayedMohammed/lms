<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthRegistrationLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => config('constants.SYSTEM_ROLES.USER')]);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => config('constants.SYSTEM_ROLES.SUPER_ADMIN')]);
    }

    public function test_unique_user_registration_succeeds_and_creates_exactly_one_user()
    {
        $payload = [
            'name' => 'Test Registration User',
            'email' => 'NewUser@Example.com ',
            'password' => 'Password123',
            'confirm_password' => 'Password123',
            'type' => 'email',
            'device_type' => 'web',
            'device_id' => 'device-reg-1',
        ];

        $response = $this->postJson('/api/user-signup', $payload);
        $response->assertStatus(200);

        $this->assertEquals(1, User::where('email', 'newuser@example.com')->count());

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotEquals('Password123', $user->password);
        $this->assertTrue(Hash::check('Password123', $user->password));
    }

    public function test_newly_registered_user_can_immediately_login()
    {
        $regPayload = [
            'name' => 'Immediate Login User',
            'email' => 'immed_login@example.com',
            'password' => 'SecurePass2026',
            'confirm_password' => 'SecurePass2026',
            'type' => 'email',
            'device_type' => 'web',
            'device_id' => 'device-reg-2',
        ];

        $regResponse = $this->postJson('/api/user-signup', $regPayload);
        $regResponse->assertStatus(200);

        $loginResponse = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => ' IMMED_LOGIN@example.com ',
            'password' => 'SecurePass2026',
            'device_type' => 'web',
            'device_id' => 'device-reg-2',
        ]);

        $loginResponse->assertStatus(200);
        $loginData = $loginResponse->json();
        $this->assertTrue($loginData['success'] ?? $loginData['status'] ?? false);
        $token = $loginData['data']['token'] ?? $loginData['token'] ?? null;
        $this->assertNotEmpty($token);
    }

    public function test_duplicate_email_registration_returns_validation_error()
    {
        User::factory()->create([
            'email' => 'existing@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $response = $this->postJson('/api/user-signup', [
            'name' => 'Duplicate Attempt User',
            'email' => ' Existing@Example.com ',
            'password' => 'Password123',
            'confirm_password' => 'Password123',
            'type' => 'email',
            'device_type' => 'web',
            'device_id' => 'device-dup-1',
        ]);

        $response->assertStatus(422);
        $this->assertEquals(1, User::where('email', 'existing@example.com')->count());
    }

    public function test_wrong_password_login_is_rejected()
    {
        User::factory()->create([
            'email' => 'user_wrong_pass@example.com',
            'password' => Hash::make('CorrectPassword123'),
        ]);

        $response = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'user_wrong_pass@example.com',
            'password' => 'WrongPassword123',
            'device_type' => 'web',
            'device_id' => 'device-wrong-1',
        ]);

        $response->assertStatus(422)
            ->assertJsonMissingPath('errors.error_code');
    }

    public function test_missing_user_login_returns_machine_readable_account_not_found_code()
    {
        $response = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'missing@example.com',
            'password' => 'Password123',
            'device_type' => 'web',
            'device_id' => 'device-missing-1',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.error_code', 'ACCOUNT_NOT_FOUND');
    }

    public function test_protected_profile_endpoint_succeeds_with_bearer_token()
    {
        $user = User::factory()->create([
            'email' => 'profile_test@example.com',
            'password' => Hash::make('Password123'),
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $loginResponse = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'profile_test@example.com',
            'password' => 'Password123',
            'device_type' => 'web',
            'device_id' => 'device-prof-1',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('data.token');

        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/get-user-details');

        $meResponse->assertStatus(200);
    }
}

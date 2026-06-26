<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate([
            'name' => config('constants.SYSTEM_ROLES.USER'),
            'guard_name' => 'web',
        ]);
    }

    public function test_mobile_registration_requires_firebase_token(): void
    {
        $this->postJson('/api/mobile-registration', [
            'name' => 'Phone User',
            'mobile' => '1000000000',
            'country_calling_code' => '+20',
            'password' => 'Password#123',
            'confirm_password' => 'Password#123',
        ])->assertStatus(422)
            ->assertJsonPath('error', true);

        $this->assertDatabaseMissing('users', ['mobile' => '1000000000']);
    }

    public function test_mobile_login_matches_country_calling_code(): void
    {
        $user = User::factory()->create([
            'mobile' => '1000000001',
            'country_calling_code' => '+20',
            'password' => Hash::make('Password#123'),
            'is_active' => 1,
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $this->postJson('/api/mobile-login', [
            'mobile' => '1000000001',
            'country_calling_code' => '+1',
            'password' => 'Password#123',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'User Not Found');
    }

    public function test_duplicate_email_signup_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'duplicate@example.com',
            'password' => Hash::make('Password#123'),
            'is_active' => 1,
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $this->postJson('/api/user-signup', [
            'type' => 'email',
            'name' => 'Duplicate',
            'email' => 'duplicate@example.com',
            'password' => 'Password#123',
            'confirm_password' => 'Password#123',
        ])->assertStatus(422)
            ->assertJsonPath(
                'message',
                'An account with this email already exists. Please log in instead.',
            );
    }

    public function test_unsupported_social_provider_is_rejected(): void
    {
        $this->postJson('/api/social-login/not-a-provider', [
            'access_token' => 'invalid',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Unsupported social provider.');
    }

    public function test_mobile_reset_requires_phone_identity_fields(): void
    {
        $this->postJson('/api/mobile-reset-password', [
            'firebase_token' => 'invalid',
            'password' => 'Password#456',
            'confirm_password' => 'Password#456',
        ])->assertStatus(422)
            ->assertJsonPath('error', true);
    }
}

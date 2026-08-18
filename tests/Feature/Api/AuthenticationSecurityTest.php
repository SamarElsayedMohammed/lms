<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
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

    public function test_user_login_allows_login_without_device_tracking_fields(): void
    {
        $user = User::factory()->create([
            'email' => 'tracked@example.com',
            'password' => Hash::make('Password#123'),
            'is_active' => 1,
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'tracked@example.com',
            'password' => 'Password#123',
        ])->assertOk()
            ->assertJsonPath('error', false);

        $this->assertDatabaseMissing('user_devices', ['user_id' => $user->id]);
    }

    public function test_user_login_tracks_device_and_blocks_second_web_device(): void
    {
        $user = User::factory()->create([
            'email' => 'device-limit@example.com',
            'password' => Hash::make('Password#123'),
            'is_active' => 1,
            'allowed_devices_count' => 1,
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'device-limit@example.com',
            'password' => 'Password#123',
            'device_type' => 'web',
            'device_id' => 'browser-a',
        ])->assertOk()
            ->assertJsonPath('error', false)
            ->assertJsonPath('data.email', 'device-limit@example.com');

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_type' => 'web',
            'device_id' => 'browser-a',
        ]);

        $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'device-limit@example.com',
            'password' => 'Password#123',
            'device_type' => 'web',
            'device_id' => 'browser-b',
        ])->assertStatus(403)
            ->assertJsonPath('error', true)
            ->assertJsonPath('errors.error_code', 'DEVICE_LIMIT_EXCEEDED');

        $this->assertSame(1, UserDevice::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_id' => 'browser-a',
        ]);
    }

    public function test_user_profile_route_alias_returns_user_details(): void
    {
        $user = User::factory()->create(['is_active' => 1]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));
        Sanctum::actingAs($user);

        $details = $this->getJson('/api/get-user-details');
        $profile = $this->getJson('/api/user/profile');

        $details->assertOk()
            ->assertJsonPath('error', false)
            ->assertJsonPath('data.id', $user->id);

        $profile->assertOk()
            ->assertExactJson($details->json());
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

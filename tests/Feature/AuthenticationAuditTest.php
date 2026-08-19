<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AuthenticationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => config('constants.SYSTEM_ROLES.USER')]);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => config('constants.SYSTEM_ROLES.SUPER_ADMIN')]);
    }

    public function test_login_exceeding_device_limit_returns_403_and_clear_devices_resets_sessions()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'allowed_devices_count' => 1,
            'is_active' => 1,
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        // Login first device
        $response1 = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_type' => 'web',
            'device_id' => 'device-1',
            'device_name' => 'Chrome PC 1'
        ]);
        $response1->assertStatus(200);
        $this->assertEquals(1, UserDevice::where('user_id', $user->id)->count());

        // Login second device without clear_devices -> blocked with 403
        $response2 = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_type' => 'web',
            'device_id' => 'device-2',
            'device_name' => 'Chrome PC 2'
        ]);
        $response2->assertStatus(403)
            ->assertJsonPath('errors.error_code', 'DEVICE_LIMIT_EXCEEDED');

        // Login with clear_devices = true -> clears previous devices and succeeds
        $response3 = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_type' => 'web',
            'device_id' => 'device-2',
            'device_name' => 'Chrome PC 2',
            'clear_devices' => true,
        ]);
        $response3->assertStatus(200);

        // Assert device-1 was removed, and only device-2 exists
        $this->assertEquals(1, UserDevice::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('user_devices', ['device_id' => 'device-2']);
        $this->assertDatabaseMissing('user_devices', ['device_id' => 'device-1']);
    }

    public function test_same_device_relogin_updates_metadata_without_consuming_new_slot()
    {
        $user = User::factory()->create([
            'email' => 'limit@example.com',
            'password' => Hash::make('password123'),
            'allowed_devices_count' => 1,
            'is_active' => 1,
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $this->postJson('/api/user-login', [
            'type' => 'email', 'email' => 'limit@example.com', 'password' => 'password123',
            'device_type' => 'web', 'device_id' => 'device-web-1', 'device_name' => 'Old Name'
        ])->assertStatus(200);

        $this->assertEquals(1, UserDevice::where('user_id', $user->id)->count());

        // Re-login with SAME device_id
        $this->postJson('/api/user-login', [
            'type' => 'email', 'email' => 'limit@example.com', 'password' => 'password123',
            'device_type' => 'web', 'device_id' => 'device-web-1', 'device_name' => 'Updated Name'
        ])->assertStatus(200);

        $this->assertEquals(1, UserDevice::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('user_devices', [
            'device_id' => 'device-web-1',
            'device_name' => 'Updated Name',
        ]);
    }

    public function test_invalid_credentials_return_error()
    {
        $user = User::factory()->create([
            'email' => 'test2@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $response = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'test2@example.com',
            'password' => 'wrongpassword',
            'device_type' => 'web',
            'device_id' => 'device-1'
        ]);

        $response->assertJson([
            'status' => false,
            'message' => 'Invalid email or password.'
        ]);
    }

    public function test_inactive_user_account_returns_clear_message()
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('password123'),
            'deleted_at' => now()
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $response = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'inactive@example.com',
            'password' => 'password123',
            'device_type' => 'web',
            'device_id' => 'device-1'
        ]);

        $response->assertJson([
            'status' => false,
            'message' => 'User is deactivated. Please contact the administrator.'
        ]);
    }
}

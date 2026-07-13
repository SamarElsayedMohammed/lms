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

    public function test_login_from_second_web_device_automatically_revokes_first_session_and_returns_200()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
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

        // Login second device of SAME type
        $response2 = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_type' => 'web',
            'device_id' => 'device-2',
            'device_name' => 'Chrome PC 2'
        ]);
        $response2->assertStatus(200);
        
        // Assert device-1 was removed, and only device-2 exists
        $this->assertEquals(1, UserDevice::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('user_devices', ['device_id' => 'device-2']);
        $this->assertDatabaseMissing('user_devices', ['device_id' => 'device-1']);

        // Assert token1 is revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'device-1'
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'device-2'
        ]);
    }

    public function test_login_from_third_device_total_limit_automatically_revokes_oldest_session()
    {
        $user = User::factory()->create([
            'email' => 'limit@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $user->allowed_devices_count = 3;
        $user->save();

        $this->postJson('/api/user-login', [
            'type' => 'email', 'email' => 'limit@example.com', 'password' => 'password123',
            'device_type' => 'web', 'device_id' => 'device-web-1'
        ])->assertStatus(200);
        
        sleep(1);

        $this->postJson('/api/user-login', [
            'type' => 'email', 'email' => 'limit@example.com', 'password' => 'password123',
            'device_type' => 'android', 'device_id' => 'device-android-1'
        ])->assertStatus(200);

        sleep(1);

        $this->postJson('/api/user-login', [
            'type' => 'email', 'email' => 'limit@example.com', 'password' => 'password123',
            'device_type' => 'ios', 'device_id' => 'device-ios-1'
        ])->assertStatus(200);

        $this->assertEquals(3, UserDevice::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'device-web-1']);

        // Login device 4 (desktop)
        $response4 = $this->postJson('/api/user-login', [
            'type' => 'email', 'email' => 'limit@example.com', 'password' => 'password123',
            'device_type' => 'desktop', 'device_id' => 'device-desktop-1'
        ]);
        
        $response4->assertStatus(200);
        $this->assertEquals(3, UserDevice::where('user_id', $user->id)->count());

        $this->assertDatabaseMissing('user_devices', ['device_id' => 'device-web-1']);
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'device-web-1']);
        
        $this->assertDatabaseHas('user_devices', ['device_id' => 'device-desktop-1']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'device-desktop-1']);
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

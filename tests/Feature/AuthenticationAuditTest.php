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

    public function test_login_exceeding_device_limit_implicitly_evicts_oldest_device_and_succeeds()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'allowed_devices_count' => 1,
            'is_active' => 1,
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        // 1. Login first device (device-1)
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
        $this->assertDatabaseHas('user_devices', ['device_id' => 'device-1']);

        // 2. Login second device (device-2) on account with limit=1 -> succeeds via implicit eviction!
        $response2 = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'test@example.com',
            'password' => 'password123',
            'device_type' => 'web',
            'device_id' => 'device-2',
            'device_name' => 'Chrome PC 2'
        ]);
        $response2->assertStatus(200);

        // Assert device count remains 1, device-1 was evicted, and device-2 is active
        $this->assertEquals(1, UserDevice::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('user_devices', ['device_id' => 'device-2']);
        $this->assertDatabaseMissing('user_devices', ['device_id' => 'device-1']);

        // Assert Sanctum tokens for device-1 were revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'device-1',
        ]);
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
            'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.'
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
            'message' => 'تم تعطيل الحساب. يرجى التواصل مع الدعم.'
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\EmailPasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class IdentityAuthorizationAdversarialCertificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mail.default', 'log');
        Config::set('mail.from.address', 'noreply@skillso.com');
        Config::set('mail.from.name', 'Skillso');

        Role::firstOrCreate(['name' => config('constants.SYSTEM_ROLES.USER'), 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => config('constants.SYSTEM_ROLES.SUPER_ADMIN'), 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    }

    /**
     * ATTACK-01 / INV-01: Anonymous request to protected endpoints is denied (401).
     */
    public function test_attack_01_anonymous_request_to_protected_endpoints_is_denied(): void
    {
        $this->getJson('/api/get-user-details')->assertStatus(401);
        $this->getJson('/api/user/certificates')->assertStatus(401);
        $this->getJson('/api/cart')->assertStatus(401);
        $this->postJson('/api/logout')->assertStatus(401);
    }

    /**
     * ATTACK-02 / INV-01: Malformed, truncated, or fake Bearer tokens return 401.
     */
    public function test_attack_02_malformed_and_fake_tokens_return_401(): void
    {
        $this->withToken('fake-bearer-token-123456789')
            ->getJson('/api/get-user-details')
            ->assertStatus(401);

        $this->withToken('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.invalid.signature')
            ->getJson('/api/get-user-details')
            ->assertStatus(401);

        $this->withHeaders(['Authorization' => 'Bearer '])
            ->getJson('/api/get-user-details')
            ->assertStatus(401);
    }

    /**
     * ATTACK-03 / INV-07: Logout revokes the token; replaying the revoked token returns 401.
     */
    public function test_attack_03_logout_revokes_token_and_replay_is_denied(): void
    {
        $user = User::factory()->create([
            'email' => 'logout-test@example.com',
            'password' => Hash::make('Secret#123'),
            'is_active' => 1,
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $loginResponse = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'logout-test@example.com',
            'password' => 'Secret#123',
            'device_id' => 'dev-logout-1',
        ])->assertOk();

        $token = $loginResponse->json('data.token');
        $this->assertNotEmpty($token);

        // Authenticated request works
        $this->withToken($token)
            ->getJson('/api/get-user-details')
            ->assertOk();

        // Logout
        $this->withToken($token)
            ->postJson('/api/logout', ['device_id' => 'dev-logout-1'])
            ->assertOk();

        // Ensure database token was actually deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'dev-logout-1',
        ]);

        // Simulate new request without in-memory cached guard state
        $this->app['auth']->forgetGuards();

        // Replaying same token must return 401
        $this->withToken($token)
            ->getJson('/api/get-user-details')
            ->assertStatus(401);
    }

    /**
     * ATTACK-04 / INV-10: Suspended / deactivated user cannot perform authenticated actions (403).
     */
    public function test_attack_04_suspended_user_is_denied_access(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => Hash::make('Secret#123'),
            'is_active' => 0, // Suspended
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        // Attempt login -> denied
        $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'suspended@example.com',
            'password' => 'Secret#123',
        ])->assertStatus(422);

        // Even with active Sanctum session, EnsureAccessToken middleware blocks with 403
        Sanctum::actingAs($user);
        $this->getJson('/api/get-user-details')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Account is suspended or deactivated.');
    }

    /**
     * ATTACK-05 / INV-04: BOLA on Profile — User A cannot access or mutate User B's profile via ID spoofing.
     */
    public function test_attack_05_bola_profile_isolation(): void
    {
        $userA = User::factory()->create(['name' => 'User Alice', 'is_active' => 1]);
        $userA->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $userB = User::factory()->create(['name' => 'User Bob', 'is_active' => 1]);
        $userB->assignRole(config('constants.SYSTEM_ROLES.USER'));

        Sanctum::actingAs($userA);

        // User A requests user details with user_id = Bob
        $res = $this->getJson('/api/get-user-details?user_id=' . $userB->id);
        $res->assertOk();

        // Must return Alice's details, NOT Bob's
        $this->assertEquals($userA->id, $res->json('data.id'));
        $this->assertEquals('User Alice', $res->json('data.name'));
    }

    /**
     * ATTACK-08 / INV-04: BOLA on Certificate Download — User A cannot download User B's certificate.
     */
    public function test_attack_08_bola_certificate_download(): void
    {
        $userA = User::factory()->create(['is_active' => 1]);
        $userB = User::factory()->create(['is_active' => 1]);
        $course = Course::factory()->create(['is_active' => 1, 'is_free' => false]);

        // Certificate issued only to User B
        CourseCertificate::create([
            'user_id' => $userB->id,
            'course_id' => $course->id,
            'certificate_number' => '583104927641805273',
            'student_name' => $userB->name,
            'arabic_title' => $course->title,
            'issued_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        // User A tries to download User B's course certificate
        Sanctum::actingAs($userA);
        $this->postJson('/api/certificate/course/download', [
            'course_id' => $course->id,
        ])->assertForbidden();
    }

    /**
     * ATTACK-09 / INV-05: Normal regular user calling admin routes is denied (403).
     */
    public function test_attack_09_normal_user_calling_admin_endpoints_is_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => 1]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/users')->assertStatus(403);
        $this->getJson('/api/admin/certificates')->assertStatus(403);
        $this->getJson('/api/admin/payment-methods')->assertStatus(403);
        $this->postJson('/api/admin/certificates', [
            'user_id' => $user->id,
            'course_id' => 1,
        ])->assertStatus(403);
    }

    /**
     * ATTACK-10 / INV-06: Privilege self-escalation via mass assignment during registration is blocked.
     */
    public function test_attack_10_privilege_escalation_in_signup_is_blocked(): void
    {
        $this->postJson('/api/user-signup', [
            'type' => 'email',
            'name' => 'Attacker',
            'email' => 'attacker@example.com',
            'password' => 'Secret#123',
            'confirm_password' => 'Secret#123',
            'role' => 'Super Admin',
            'is_admin' => true,
            'is_superuser' => 1,
            'role_id' => 1,
            'wallet_balance' => 999999,
        ])->assertOk();

        $user = User::where('email', 'attacker@example.com')->first();
        $this->assertNotNull($user);

        // Verify user has regular student/user role, NOT Super Admin or Admin
        $this->assertTrue($user->hasRole('student') || $user->hasRole(config('constants.SYSTEM_ROLES.USER')));
        $this->assertFalse($user->hasRole(config('constants.SYSTEM_ROLES.SUPER_ADMIN')));
        $this->assertFalse($user->hasRole('Admin'));
        $this->assertEquals(0, (float) ($user->wallet_balance ?? 0));
    }

    /**
     * ATTACK-13 & ATTACK-14 / INV-12: Password reset single-use and expiration enforcement.
     */
    public function test_attack_13_and_14_password_reset_otp_single_use_and_invalidation(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-flow@example.com',
            'password' => Hash::make('OldPassword#1'),
            'is_active' => 1,
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $service = app(EmailPasswordResetService::class);
        $otp = $service->sendOtp($user);

        // Verify code
        $this->postJson('/api/verify-reset-code', [
            'email' => 'reset-flow@example.com',
            'code' => $otp,
        ])->assertOk()->assertJsonPath('data.verified', true);

        // First Reset -> Success
        $this->postJson('/api/reset-password', [
            'email' => 'reset-flow@example.com',
            'code' => $otp,
            'password' => 'NewPassword#2',
            'confirm_password' => 'NewPassword#2',
        ])->assertOk();

        // Verify password was changed
        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword#2', $user->password));

        // Second Reset attempt with same OTP -> MUST FAIL (single use)
        $this->postJson('/api/reset-password', [
            'email' => 'reset-flow@example.com',
            'code' => $otp,
            'password' => 'ThirdPassword#3',
            'confirm_password' => 'ThirdPassword#3',
        ])->assertStatus(422);
    }

    /**
     * ATTACK-15 / INV-13: Password reset returns generic message for non-existent emails (no enumeration).
     */
    public function test_attack_15_forgot_password_enumeration_resistance(): void
    {
        $existing = User::factory()->create(['email' => 'real-user@example.com', 'is_active' => 1]);

        $resReal = $this->postJson('/api/forgot-password', ['email' => 'real-user@example.com']);
        $resFake = $this->postJson('/api/forgot-password', ['email' => 'non-existent-user@example.com']);

        $resReal->assertOk();
        $resFake->assertOk();

        // Both return identical generic message
        $this->assertEquals($resReal->json('message'), $resFake->json('message'));
    }

    /**
     * ATTACK-28 & ATTACK-29 / INV-08, INV-09: Logout-others revokes all other device sessions.
     */
    public function test_attack_28_and_29_logout_others_revokes_other_sessions(): void
    {
        $user = User::factory()->create([
            'email' => 'multi-dev@example.com',
            'password' => Hash::make('Secret#123'),
            'is_active' => 1,
            'allowed_devices_count' => 5,
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        // Login Device 1
        $login1 = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'multi-dev@example.com',
            'password' => 'Secret#123',
            'device_type' => 'web',
            'device_id' => 'dev-session-1',
        ])->assertOk();
        $token1 = $login1->json('data.token');

        // Login Device 2
        $login2 = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'multi-dev@example.com',
            'password' => 'Secret#123',
            'device_type' => 'android',
            'device_id' => 'dev-session-2',
        ])->assertOk();
        $token2 = $login2->json('data.token');

        // Both devices can make authenticated requests
        $this->app['auth']->forgetGuards();
        $this->withToken($token1)->getJson('/api/get-user-details')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token2)->getJson('/api/get-user-details')->assertOk();

        // Device 2 calls logout-others
        $this->app['auth']->forgetGuards();
        $this->withToken($token2)->postJson('/api/logout-others', [
            'device_id' => 'dev-session-2',
        ])->assertOk();

        // Ensure database token for device 1 was actually deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'dev-session-1',
        ]);

        // Simulate fresh HTTP request for device 1
        $this->app['auth']->forgetGuards();

        // Device 1 is now revoked (401)
        $this->withToken($token1)->getJson('/api/get-user-details')->assertStatus(401);

        // Simulate fresh HTTP request for device 2
        $this->app['auth']->forgetGuards();

        // Device 2 remains valid
        $this->withToken($token2)->getJson('/api/get-user-details')->assertOk();
    }
}

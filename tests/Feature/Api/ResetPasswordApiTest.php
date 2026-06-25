<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\EmailPasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ResetPasswordApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => config('constants.SYSTEM_ROLES.USER'), 'guard_name' => 'web']);
    }

    public function test_forgot_password_returns_generic_success_for_unknown_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'unknown@example.com',
        ]);

        $response->assertOk()->assertJsonPath('error', false);
        Mail::assertNothingSent();
    }

    public function test_forgot_password_sends_otp_email_for_existing_user(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('old-password'),
            'is_active' => 1,
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'user@example.com',
        ]);

        $response->assertOk()->assertJsonPath('error', false);
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'user@example.com']);
    }

    public function test_verify_and_reset_password_with_otp(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => Hash::make('old-password'),
            'is_active' => 1,
        ]);
        $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

        $service = app(EmailPasswordResetService::class);
        $otp = $service->sendOtp($user);

        $verifyResponse = $this->postJson('/api/verify-reset-code', [
            'email' => 'reset@example.com',
            'code' => $otp,
        ]);

        $verifyResponse->assertOk()
            ->assertJsonPath('data.verified', true);

        $resetResponse = $this->postJson('/api/reset-password', [
            'email' => 'reset@example.com',
            'code' => $otp,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $resetResponse->assertOk()->assertJsonPath('error', false);

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'reset@example.com')->count());
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_delete_account_with_zero_balance()
    {
        $password = 'password';
        $user = User::factory()->create([
            'wallet_balance' => 0,
            'password' => Hash::make($password),
            'type' => 'email',
            'is_active' => 1,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/delete-account', [
            'password' => $password,
            'confirm_deletion' => true,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', true)
            ->assertJsonPath('error', false)
            ->assertJsonPath('code', 200)
            ->assertJsonPath(
                'message',
                'Your account has been deactivated and personal access has been removed. Financial records are retained where legally required.',
            );

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_user_cannot_delete_account_with_positive_balance()
    {
        $password = 'password';
        $user = User::factory()->create([
            'wallet_balance' => 10,
            'password' => Hash::make($password),
            'type' => 'email',
            'is_active' => 1,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/delete-account', [
            'password' => $password,
            'confirm_deletion' => true,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', false)
            ->assertJsonPath('error', true)
            ->assertJsonPath('code', 422)
            ->assertJsonPath(
                'message',
                'You cannot delete your account because you have a remaining balance in your wallet. Please withdraw or spend your funds before deleting your account.',
            );

        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
    }
}

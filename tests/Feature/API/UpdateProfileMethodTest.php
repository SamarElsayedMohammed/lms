<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UpdateProfileMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_profile_with_put_json(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/update-profile', [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'mobile' => '1126181353',
            'country_calling_code' => '20',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'mobile' => '1126181353',
            'country_calling_code' => '20',
        ]);
    }

    public function test_authenticated_user_can_partially_update_profile_with_patch_json(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'name' => 'Original Name',
        ]);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/update-profile', [
            'name' => 'Patched Name',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Patched Name',
        ]);
    }
}

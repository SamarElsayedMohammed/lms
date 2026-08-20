<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin panel locale: when a request hits a panel route, locale should be set to Arabic.
 */
final class AdminLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_request_uses_arabic_locale(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/dashboard-data');

        $response->assertStatus(200);
        $this->assertEquals('ar', app()->getLocale());
    }
}

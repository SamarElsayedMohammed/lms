<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Admin panel locale: when a request hits a panel route, locale should be set to Arabic.
 */
final class AdminLocaleTest extends TestCase
{
    public function test_admin_dashboard_request_uses_arabic_locale(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSee('لوحة التحكم', false);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class ContactMessageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
    }

    public function test_authenticated_support_message_uses_user_identity(): void
    {
        $user = User::factory()->create([
            'name' => 'Support Student',
            'email' => 'support-student@example.com',
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/contact-us', [
            'subject' => 'Help with my course',
            'message' => 'Please help me access the next lesson.',
        ]);

        $response->assertOk()->assertJsonPath('error', false);
        $this->assertDatabaseHas('contact_messages', [
            'user_id' => $user->id,
            'first_name' => $user->name,
            'email' => $user->email,
            'subject' => 'Help with my course',
        ]);
    }
}

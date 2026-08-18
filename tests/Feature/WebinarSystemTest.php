<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WebinarSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instructor = User::factory()->create();
    }

    public function test_capacity_limits_block_registration()
    {
        $user = User::factory()->create();
        $user2 = User::factory()->create();
        
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Test',
            'slug' => 'test-webinar',
            'start_at' => now()->addDays(1),
            'duration' => 60,
            'is_free' => true,
            'price' => 0,
            'provider' => 'jitsi',
            'max_attendees' => 1,
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        // First registration should succeed
        $response1 = $this->actingAs($user)->postJson("/api/webinars/{$webinar->slug}/register");
        $response1->assertStatus(200);

        // Second registration should fail due to capacity
        $response2 = $this->actingAs($user2)->postJson("/api/webinars/{$webinar->slug}/register");
        $response2->assertStatus(409);
        $this->assertEquals('This webinar is full. No more registrations allowed.', $response2->json('message'));
    }

    public function test_json_config_hack_migrates_correctly()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $response = $this->actingAs($admin)->postJson('/api/admin/webinars', [
            'instructor_id' => $this->instructor->id,
            'title' => 'Hack Test',
            'start_at' => now()->addDays(1)->toDateTimeString(),
            'duration' => 60,
            'is_free' => true,
            'provider' => 'jitsi',
            'features' => [
                'Normal Feature',
                '__skillso_webinar_config_v1__:{"allow_chat":true}'
            ]
        ]);

        $response->assertStatus(201);
        $webinar = Webinar::where('title', 'Hack Test')->first();

        $this->assertEquals(['Normal Feature'], $webinar->features);
        $this->assertEquals(['allow_chat' => true], $webinar->config);
    }

    public function test_wallet_payment_fails_gracefully_on_insufficient_funds()
    {
        $user = User::factory()->create(['wallet_balance' => 10]); // Insufficient

        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Paid Webinar',
            'slug' => 'paid-webinar',
            'start_at' => now()->addDays(1),
            'duration' => 60,
            'is_free' => false,
            'price' => 50,
            'provider' => 'zoom',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        $response = $this->actingAs($user)->postJson("/api/user/wallet/webinars/{$webinar->slug}/register", [
            'use_wallet' => true
        ], [
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()
        ]);

        $response->assertStatus(400);
        $this->assertEquals('insufficient_funds', $response->json('message'));
    }

    public function test_wallet_balance_deducted_transactionally()
    {
        $user = User::factory()->create(['wallet_balance' => 100]); // Sufficient

        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Paid Webinar 2',
            'slug' => 'paid-webinar-2',
            'start_at' => now()->addDays(1),
            'duration' => 60,
            'is_free' => false,
            'price' => 50,
            'provider' => 'zoom',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        $response = $this->actingAs($user)->postJson("/api/user/wallet/webinars/{$webinar->slug}/register", [
            'use_wallet' => true
        ], [
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));

        $this->assertEquals(50, $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('webinar_registrations', [
            'webinar_id' => $webinar->id,
            'user_id' => $user->id,
            'payment_status' => 'paid'
        ]);
    }

    public function test_idempotency_prevents_duplicate_registration()
    {
        $user = User::factory()->create(['wallet_balance' => 100]);
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Idempotent Webinar',
            'slug' => 'idem-webinar',
            'start_at' => now()->addDays(1),
            'duration' => 60,
            'is_free' => false,
            'price' => 10,
            'provider' => 'zoom',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        $idempotencyKey = (string) \Illuminate\Support\Str::uuid();

        // First request
        $response1 = $this->actingAs($user)->postJson("/api/user/wallet/webinars/{$webinar->slug}/register", [
            'use_wallet' => true
        ], [
            'Idempotency-Key' => $idempotencyKey
        ]);
        $response1->assertStatus(200);

        // Second duplicate request
        $response2 = $this->actingAs($user)->postJson("/api/user/wallet/webinars/{$webinar->slug}/register", [
            'use_wallet' => true
        ], [
            'Idempotency-Key' => $idempotencyKey
        ]);
        $response2->assertStatus(409); // Conflict from duplicate middleware
    }

    public function test_public_endpoint_hides_pii_data_and_credentials()
    {
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'PII Test',
            'slug' => 'pii-test',
            'start_at' => now()->addDays(1),
            'duration' => 60,
            'is_free' => true,
            'price' => 0,
            'provider' => 'zoom',
            'join_url' => 'https://zoom.us/j/secret_123',
            'meeting_id' => 'secret_id',
            'meeting_password' => 'secret_pass',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        $user = User::factory()->create();
        WebinarRegistration::create([
            'webinar_id' => $webinar->id,
            'user_id' => $user->id,
            'payment_status' => 'free',
            'paid_amount' => 0,
        ]);

        $response = $this->getJson("/api/webinars/{$webinar->slug}");
        $response->assertStatus(200);
        
        $data = $response->json('data.webinar');
        $this->assertArrayNotHasKey('registrations', $data);
        $this->assertArrayNotHasKey('join_url', $data); // Hidden since unauthenticated
        $this->assertArrayNotHasKey('meeting_id', $data);
        $this->assertArrayNotHasKey('meeting_password', $data);
    }

    public function test_join_endpoint_enforces_live_time_gate_and_records_attendance()
    {
        $user = User::factory()->create();
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Live Join Test',
            'slug' => 'live-join-test',
            'start_at' => now()->addMinutes(5), // Within 15-minute early window
            'duration' => 60,
            'is_free' => true,
            'provider' => 'zoom',
            'join_url' => 'https://zoom.us/j/live_room',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        $registration = WebinarRegistration::create([
            'webinar_id' => $webinar->id,
            'user_id' => $user->id,
            'payment_status' => 'free',
            'paid_amount' => 0,
        ]);

        $response = $this->actingAs($user)->getJson("/api/webinars/{$webinar->slug}/join");
        $response->assertStatus(200);
        $this->assertEquals('https://zoom.us/j/live_room', $response->json('data.join_url'));

        // Verify attendance was recorded
        $this->assertTrue((bool) $registration->fresh()->attended);
        $this->assertNotNull($registration->fresh()->attended_at);
    }

    public function test_user_my_webinars_returns_registrations()
    {
        $user = User::factory()->create();
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'My Webinar List Test',
            'slug' => 'my-webinar-list-test',
            'start_at' => now()->addDays(2),
            'duration' => 60,
            'is_free' => true,
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        WebinarRegistration::create([
            'webinar_id' => $webinar->id,
            'user_id' => $user->id,
            'payment_status' => 'free',
            'paid_amount' => 0,
        ]);

        $response = $this->actingAs($user)->getJson('/api/user/my-webinars');
        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertCount(1, $response->json('data.items'));
        $this->assertEquals($webinar->title, $response->json('data.items.0.title'));
    }
}

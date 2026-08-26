<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
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

        Sanctum::actingAs($user);
        $response1 = $this->postJson("/api/webinars/{$webinar->slug}/register", [
            'name' => $user->name,
            'email' => $user->email,
            'whatsapp' => '01011111111',
        ]);
        $response1->assertStatus(200);

        Auth::forgetGuards();
        Sanctum::actingAs($user2);
        $response2 = $this->postJson("/api/webinars/{$webinar->slug}/register", [
            'name' => $user2->name,
            'email' => $user2->email,
            'whatsapp' => '01022222222',
        ]);
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

    public function test_paid_wallet_register_twice_with_new_keys_returns_409(): void
    {
        $user = User::factory()->create(['wallet_balance' => 200]);
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Paid Duplicate Webinar',
            'slug' => 'paid-dup-webinar',
            'start_at' => now()->addDays(1),
            'duration' => 60,
            'is_free' => false,
            'price' => 40,
            'provider' => 'zoom',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        $first = $this->actingAs($user)->postJson("/api/user/wallet/webinars/{$webinar->slug}/register", [
            'use_wallet' => true,
        ], [
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $first->assertStatus(200);

        $second = $this->actingAs($user)->postJson("/api/user/wallet/webinars/{$webinar->slug}/register", [
            'use_wallet' => true,
        ], [
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $second->assertStatus(409);
        $this->assertEquals(1, WebinarRegistration::where('user_id', $user->id)->where('webinar_id', $webinar->id)->count());
        $this->assertEquals(160, $user->fresh()->wallet_balance);
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

    public function test_public_show_hides_join_url_even_for_registered_users()
    {
        $user = User::factory()->create();
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Secret Join',
            'slug' => 'secret-join',
            'start_at' => now()->addDays(1),
            'duration' => 60,
            'is_free' => true,
            'price' => 0,
            'provider' => 'zoom',
            'join_url' => 'https://zoom.us/j/secret_join',
            'meeting_id' => 'hidden-id',
            'meeting_password' => 'hidden-pass',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        WebinarRegistration::create([
            'webinar_id' => $webinar->id,
            'user_id' => $user->id,
            'payment_status' => 'free',
            'paid_amount' => 0,
        ]);

        $response = $this->actingAs($user)->getJson("/api/webinars/{$webinar->slug}");
        $response->assertStatus(200);
        $data = $response->json('data.webinar');
        $this->assertArrayNotHasKey('join_url', $data);
        $this->assertArrayNotHasKey('meeting_id', $data);
        $this->assertArrayNotHasKey('meeting_password', $data);
    }

    public function test_unpublished_webinar_is_hidden_from_public_show()
    {
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Draft Webinar',
            'slug' => 'draft-hidden',
            'start_at' => now()->addDays(1),
            'duration' => 60,
            'is_free' => true,
            'provider' => 'jitsi',
            'status' => 'scheduled',
            'is_published' => false,
        ]);

        $this->getJson("/api/webinars/{$webinar->slug}")->assertStatus(404);
    }

    public function test_lifecycle_status_moves_scheduled_to_live()
    {
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Going Live',
            'slug' => 'going-live',
            'start_at' => now()->subMinutes(10),
            'duration' => 60,
            'is_free' => true,
            'provider' => 'jitsi',
            'join_url' => 'https://meet.jit.si/going-live',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        $this->getJson("/api/webinars/{$webinar->slug}")
            ->assertStatus(200)
            ->assertJsonPath('data.webinar.status', 'live');
    }

    public function test_paid_register_requires_wallet_instead_of_gateway()
    {
        $user = User::factory()->create();
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Paid No Gateway',
            'slug' => 'paid-no-gateway',
            'start_at' => now()->addDays(1),
            'duration' => 60,
            'is_free' => false,
            'price' => 80,
            'provider' => 'zoom',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        $response = $this->actingAs($user)->postJson("/api/webinars/{$webinar->slug}/register");
        $response->assertStatus(400);
        $this->assertEquals('wallet_required', $response->json('data.error_code') ?? $response->json('errors.error_code'));
    }

    public function test_registration_persists_dynamic_form_responses_and_utm_source()
    {
        $user = User::factory()->create();
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Dynamic Form Webinar',
            'slug' => 'dynamic-form-webinar',
            'start_at' => now()->addDays(2),
            'duration' => 90,
            'is_free' => true,
            'provider' => 'jitsi',
            'status' => 'scheduled',
            'is_published' => true,
            'config' => [
                'form' => [
                    'customFields' => [
                        [
                            'id' => 'field_job',
                            'name' => 'job_title',
                            'label' => 'المسمى الوظيفي',
                            'type' => 'text',
                            'required' => true,
                        ],
                        [
                            'id' => 'field_exp',
                            'name' => 'experience_level',
                            'label' => 'مستوى الخبرة',
                            'type' => 'select',
                            'options' => ['مبتدئ', 'متوسط', 'متقدم'],
                            'required' => false,
                        ]
                    ]
                ]
            ]
        ]);

        $response = $this->actingAs($user)->postJson("/api/webinars/{$webinar->slug}/register", [
            'name' => 'أحمد المهندس',
            'email' => 'ahmed.dev@example.com',
            'whatsapp' => '01012345678',
            'job_title' => 'Senior Full Stack Engineer',
            'experience_level' => 'متقدم',
            'utm_source' => 'facebook_campaign_2026',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('webinar_registrations', [
            'webinar_id' => $webinar->id,
            'user_id' => $user->id,
            'utm_source' => 'facebook_campaign_2026',
        ]);

        $registration = WebinarRegistration::where('webinar_id', $webinar->id)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($registration);
        $this->assertEquals('Senior Full Stack Engineer', $registration->form_responses['job_title'] ?? null);
        $this->assertEquals('متقدم', $registration->form_responses['experience_level'] ?? null);
    }

    public function test_registration_fails_validation_when_required_custom_field_missing()
    {
        $user = User::factory()->create();
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Strict Form Webinar',
            'slug' => 'strict-form-webinar',
            'start_at' => now()->addDays(2),
            'duration' => 60,
            'is_free' => true,
            'provider' => 'jitsi',
            'status' => 'scheduled',
            'is_published' => true,
            'config' => [
                'form' => [
                    'customFields' => [
                        [
                            'id' => 'field_company',
                            'name' => 'company_name',
                            'label' => 'جهة العمل / الشركة',
                            'type' => 'text',
                            'required' => true,
                        ]
                    ]
                ]
            ]
        ]);

        $response = $this->actingAs($user)->postJson("/api/webinars/{$webinar->slug}/register", [
            'name' => 'مستخدم بدون شركة',
            'email' => 'user@example.com',
            'whatsapp' => '01011112222',
            // company_name missing
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['company_name']);
    }

    public function test_guest_can_register_for_free_webinar_without_login(): void
    {
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Guest Free Webinar',
            'slug' => 'guest-free-webinar',
            'start_at' => now()->addDays(3),
            'duration' => 60,
            'is_free' => true,
            'price' => 0,
            'provider' => 'jitsi',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        $response = $this->postJson("/api/webinars/{$webinar->slug}/register", [
            'name' => 'سارة الضيف',
            'email' => 'sara.guest@example.com',
            'whatsapp' => '01099887766',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('webinar_registrations', [
            'webinar_id' => $webinar->id,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'sara.guest@example.com',
            'is_webinar_guest' => 1,
        ]);
        $this->assertSame(0, User::withoutWebinarGuests()->where('email', 'sara.guest@example.com')->count());
    }

    public function test_guest_duplicate_email_is_rejected(): void
    {
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Guest Dup Webinar',
            'slug' => 'guest-dup-webinar',
            'start_at' => now()->addDays(3),
            'duration' => 60,
            'is_free' => true,
            'provider' => 'jitsi',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        $payload = [
            'name' => 'مكرر',
            'email' => 'dup.guest@example.com',
            'whatsapp' => '01055554444',
        ];

        $this->postJson("/api/webinars/{$webinar->slug}/register", $payload)->assertStatus(200);
        $this->postJson("/api/webinars/{$webinar->slug}/register", $payload)->assertStatus(409);
        $this->assertEquals(1, WebinarRegistration::where('webinar_id', $webinar->id)->count());
    }

    public function test_registration_rejects_invalid_select_option_and_unknown_field(): void
    {
        $user = User::factory()->create();
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Schema Strict',
            'slug' => 'schema-strict',
            'start_at' => now()->addDays(2),
            'duration' => 60,
            'is_free' => true,
            'provider' => 'jitsi',
            'status' => 'scheduled',
            'is_published' => true,
            'config' => [
                'form' => [
                    'customFields' => [
                        [
                            'id' => 'level',
                            'key' => 'experience_level',
                            'name' => 'experience_level',
                            'label' => 'المستوى',
                            'type' => 'select',
                            'options' => ['مبتدئ', 'متوسط', 'متقدم'],
                            'required' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $invalidOption = $this->actingAs($user)->postJson("/api/webinars/{$webinar->slug}/register", [
            'name' => 'أحمد',
            'email' => 'ahmed.schema@example.com',
            'whatsapp' => '01012345678',
            'experience_level' => 'hacker',
        ]);
        $invalidOption->assertStatus(422);

        $unknownField = $this->actingAs($user)->postJson("/api/webinars/{$webinar->slug}/register", [
            'name' => 'أحمد',
            'email' => 'ahmed.schema@example.com',
            'whatsapp' => '01012345678',
            'experience_level' => 'متقدم',
            'injected_field' => 'nope',
        ]);
        $unknownField->assertStatus(422);
    }

    public function test_registration_closed_flag_is_enforced_server_side(): void
    {
        $user = User::factory()->create();
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'Closed Reg',
            'slug' => 'closed-reg',
            'start_at' => now()->addDays(4),
            'duration' => 60,
            'is_free' => true,
            'provider' => 'jitsi',
            'status' => 'scheduled',
            'is_published' => true,
            'config' => [
                'registration' => [
                    'open' => false,
                    'closedMessage' => 'التسجيل مغلق لهذا الويبنار.',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->postJson("/api/webinars/{$webinar->slug}/register", [
            'name' => 'أحمد',
            'email' => 'closed@example.com',
            'whatsapp' => '01012345678',
        ]);
        $response->assertStatus(400);
        $this->assertEquals('registration_closed', $response->json('data.error_code') ?? $response->json('errors.error_code'));
    }

    public function test_admin_config_round_trip_and_public_payload_hides_secrets(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $config = [
            'hero' => ['title' => 'ويبنار أ', 'badge' => 'ويبنار مجاني'],
            'event' => ['timezone' => 'Africa/Cairo', 'dateISO' => now()->addDays(5)->toIso8601String()],
            'outcomes' => ['items' => [['title' => 'خطة عمل', 'description' => 'خطة']]],
            'speakers' => [
                ['name' => 'مدرب أول', 'role' => 'خبير'],
                ['name' => 'مدرب ثاني', 'role' => 'ممارس'],
            ],
            'agenda' => ['items' => [['title' => 'افتتاح', 'startTime' => '8:00 م', 'enabled' => true]]],
            'faq' => ['items' => [['question' => 'هل مجاني؟', 'answer' => 'نعم', 'enabled' => true]]],
            'form' => [
                'customFields' => [
                    ['id' => 'goal', 'key' => 'goal', 'name' => 'goal', 'label' => 'الهدف', 'type' => 'textarea', 'required' => false],
                ],
            ],
            'thankYou' => [
                'title' => 'شكراً لك',
                'buttons' => [['text' => 'واتساب', 'url' => 'https://wa.me/201000000000', 'icon' => 'whatsapp', 'enabled' => true]],
            ],
            'registration' => ['open' => true],
        ];

        $create = $this->actingAs($admin)->postJson('/api/admin/webinars', [
            'instructor_id' => $this->instructor->id,
            'title' => 'ويبنار أ',
            'description' => 'وصف كامل',
            'start_at' => now()->addDays(5)->toDateTimeString(),
            'duration' => 90,
            'is_free' => true,
            'price' => 0,
            'provider' => 'jitsi',
            'join_url' => 'https://meet.jit.si/secret-room',
            'meeting_password' => 'secret-pass',
            'config' => $config,
            'is_published' => true,
        ]);
        $create->assertStatus(201);

        $slug = $create->json('data.slug');
        $this->assertNotEmpty($slug);

        $adminShow = $this->actingAs($admin)->getJson("/api/admin/webinars/{$slug}");
        $adminShow->assertStatus(200);
        $this->assertEquals('خطة عمل', $adminShow->json('data.config.outcomes.items.0.title'));
        $this->assertCount(2, $adminShow->json('data.config.speakers'));
        $this->assertEquals('واتساب', $adminShow->json('data.config.thankYou.buttons.0.text'));
        $this->assertEquals('افتتاح', $adminShow->json('data.config.agenda.items.0.title'));

        Webinar::where('slug', $slug)->update(['is_published' => true]);

        $public = $this->getJson("/api/webinars/{$slug}");
        $public->assertStatus(200);
        $publicWebinar = $public->json('data.webinar');
        $this->assertEquals('خطة عمل', $publicWebinar['config']['outcomes']['items'][0]['title']);
        $this->assertCount(2, $publicWebinar['config']['speakers']);
        $this->assertArrayNotHasKey('join_url', $publicWebinar);
        $this->assertArrayNotHasKey('meeting_password', $publicWebinar);
        $this->assertArrayNotHasKey('join_url', $publicWebinar['config'] ?? []);
    }

    public function test_second_webinar_can_use_different_form_and_thank_you_without_schema_change(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $createB = $this->actingAs($admin)->postJson('/api/admin/webinars', [
            'instructor_id' => $this->instructor->id,
            'title' => 'ويبنار ب',
            'description' => 'تكوين مختلف',
            'start_at' => now()->addDays(8)->toDateTimeString(),
            'duration' => 60,
            'is_free' => true,
            'price' => 0,
            'provider' => 'jitsi',
            'config' => [
                'hero' => ['title' => 'ويبنار ب'],
                'speakers' => [['name' => 'متحدث واحد']],
                'form' => [
                    'customFields' => [
                        ['id' => 'country', 'key' => 'country', 'name' => 'country', 'label' => 'الدولة', 'type' => 'text', 'required' => true],
                        ['id' => 'goal', 'key' => 'goal', 'name' => 'goal', 'label' => 'الهدف', 'type' => 'textarea', 'required' => false],
                    ],
                ],
                'thankYou' => [
                    'title' => 'تم بنجاح',
                    'buttons' => [
                        ['text' => 'تيليجرام', 'url' => 'https://t.me/skillso', 'icon' => 'telegram', 'enabled' => true],
                        ['text' => 'تحميل', 'url' => 'https://example.com/files.pdf', 'icon' => 'download', 'enabled' => true],
                    ],
                ],
            ],
        ]);
        $createB->assertStatus(201);
        $slugB = $createB->json('data.slug');
        Webinar::where('slug', $slugB)->update(['is_published' => true]);

        $guest = $this->postJson("/api/webinars/{$slugB}/register", [
            'name' => 'ضيف ب',
            'phone' => '01077776666',
            'country' => 'مصر',
            'goal' => 'تطوير المسار',
        ]);
        $guest->assertStatus(200);

        $registration = WebinarRegistration::where('webinar_id', Webinar::where('slug', $slugB)->value('id'))->first();
        $this->assertEquals('مصر', $registration->form_responses['country'] ?? null);
        $this->assertEquals('تطوير المسار', $registration->form_responses['goal'] ?? null);
        $this->assertIsArray($registration->form_responses['_schema'] ?? null);

        $public = $this->getJson("/api/webinars/{$slugB}");
        $public->assertStatus(200);
        $this->assertCount(2, $public->json('data.webinar.config.thankYou.buttons'));
        $this->assertCount(1, $public->json('data.webinar.config.speakers'));
    }

    public function test_guest_signup_converts_webinar_guest_without_duplicate_user(): void
    {
        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'تحويل حساب الضيف',
            'slug' => 'guest-convert-webinar',
            'start_at' => now()->addDays(4),
            'duration' => 60,
            'is_free' => true,
            'price' => 0,
            'provider' => 'jitsi',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        $this->postJson("/api/webinars/{$webinar->slug}/register", [
            'name' => 'ضيف للتحويل',
            'email' => 'convert.guest@example.com',
            'whatsapp' => '01011112222',
        ])->assertStatus(200);

        $guest = User::where('email', 'convert.guest@example.com')->first();
        $this->assertNotNull($guest);
        $this->assertTrue($guest->isWebinarGuest());
        $registrationId = WebinarRegistration::where('user_id', $guest->id)->where('webinar_id', $webinar->id)->value('id');

        $signup = $this->postJson('/api/user-signup', [
            'type' => 'email',
            'name' => 'حساب سكيلسو',
            'email' => 'convert.guest@example.com',
            'password' => 'Secret#123',
            'confirm_password' => 'Secret#123',
        ]);

        $signup->assertSuccessful();
        $this->assertSame(1, User::where('email', 'convert.guest@example.com')->count());
        $converted = User::where('email', 'convert.guest@example.com')->first();
        $this->assertFalse($converted->isWebinarGuest());
        $this->assertFalse((bool) $converted->is_webinar_guest);
        $this->assertSame($registrationId, WebinarRegistration::where('user_id', $converted->id)->where('webinar_id', $webinar->id)->value('id'));
    }

    public function test_historical_schema_label_survives_admin_field_rename(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'مخطط تاريخي',
            'slug' => 'historical-schema-webinar',
            'start_at' => now()->addDays(6),
            'duration' => 60,
            'is_free' => true,
            'provider' => 'jitsi',
            'status' => 'scheduled',
            'is_published' => true,
            'config' => [
                'form' => [
                    'customFields' => [
                        ['id' => 'experience', 'key' => 'experience', 'name' => 'experience', 'label' => 'مستوى الخبرة', 'type' => 'select', 'required' => true, 'options' => ['مبتدئ', 'محترف']],
                    ],
                ],
            ],
        ]);

        $this->postJson("/api/webinars/{$webinar->slug}/register", [
            'name' => 'مستخدم أ',
            'email' => 'hist.a@example.com',
            'whatsapp' => '01000001111',
            'experience' => 'مبتدئ',
        ])->assertStatus(200);

        $this->actingAs($admin)->putJson("/api/admin/webinars/{$webinar->slug}", [
            'title' => 'مخطط تاريخي',
            'config' => [
                'form' => [
                    'customFields' => [
                        ['id' => 'experience', 'key' => 'experience', 'name' => 'experience', 'label' => 'خبرتك الحالية', 'type' => 'select', 'required' => true, 'options' => ['مبتدئ', 'محترف']],
                    ],
                ],
            ],
        ])->assertOk();

        $registrants = $this->actingAs($admin)->getJson("/api/admin/webinars/{$webinar->slug}/registrants");
        $registrants->assertOk();
        $row = collect($registrants->json('data.registrants'))->first();
        $this->assertEquals('مبتدئ', $row['form_responses']['experience'] ?? null);
        $schemaLabels = collect($row['form_responses']['_schema'])->pluck('label')->all();
        $this->assertContains('مستوى الخبرة', $schemaLabels);
        $this->assertNotContains('unknown_field_34', $schemaLabels);
    }

    public function test_multipart_config_json_string_does_not_become_object_object(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $create = $this->actingAs($admin)->postJson('/api/admin/webinars', [
            'instructor_id' => $this->instructor->id,
            'title' => 'ويبنار مرفقات',
            'description' => 'وصف',
            'start_at' => now()->addDays(8)->toDateTimeString(),
            'duration' => 75,
            'is_free' => true,
            'provider' => 'jitsi',
            'config' => [
                'hero' => ['title' => 'عنوان محفوظ'],
                'agenda' => ['items' => [['title' => 'افتتاح', 'enabled' => true]]],
                'thankYou' => ['title' => 'شكراً بعد الرفع'],
            ],
            'is_published' => true,
        ]);
        $create->assertStatus(201);
        $slug = $create->json('data.slug');

        $configJson = json_encode([
            'hero' => ['title' => 'عنوان محفوظ'],
            'agenda' => ['items' => [['title' => 'افتتاح', 'enabled' => true]]],
            'outcomes' => ['items' => [['title' => 'نتيجة']]],
            'thankYou' => ['title' => 'شكراً بعد الرفع'],
        ], JSON_UNESCAPED_UNICODE);

        $update = $this->actingAs($admin)
            ->call('PUT', "/api/admin/webinars/{$slug}", [
                'title' => 'ويبنار مرفقات',
                'description' => 'وصف',
                'start_at' => now()->addDays(8)->toDateTimeString(),
                'duration' => 75,
                'config' => $configJson,
            ], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $update->assertOk();
        $show = $this->actingAs($admin)->getJson("/api/admin/webinars/{$slug}");
        $show->assertOk();
        $this->assertSame('عنوان محفوظ', $show->json('data.config.hero.title'));
        $this->assertSame('افتتاح', $show->json('data.config.agenda.items.0.title'));
        $this->assertSame('شكراً بعد الرفع', $show->json('data.config.thankYou.title'));
        $this->assertNotSame('[object Object]', $show->json('data.config.hero'));
    }

    public function test_registration_dispatches_notification_without_meeting_secrets(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $webinar = Webinar::create([
            'instructor_id' => $this->instructor->id,
            'title' => 'ويبنار إشعار',
            'slug' => 'notify-webinar',
            'start_at' => now()->addDays(2),
            'duration' => 60,
            'is_free' => true,
            'provider' => 'jitsi',
            'join_url' => 'https://meet.jit.si/secret-room',
            'meeting_password' => 'secret-pass',
            'status' => 'scheduled',
            'is_published' => true,
        ]);

        $this->postJson("/api/webinars/{$webinar->slug}/register", [
            'name' => 'مستلم الإشعار',
            'email' => 'notify.guest@example.com',
            'whatsapp' => '01033334444',
        ])->assertStatus(200);

        $user = User::where('email', 'notify.guest@example.com')->first();
        \Illuminate\Support\Facades\Notification::assertSentTo(
            $user,
            \App\Notifications\WebinarRegisteredNotification::class,
            function (\App\Notifications\WebinarRegisteredNotification $notification) use ($user) {
                $encoded = json_encode($notification->toArray($user));
                return !str_contains((string) $encoded, 'secret-room')
                    && !str_contains((string) $encoded, 'secret-pass')
                    && !str_contains((string) $encoded, 'join_url');
            }
        );
    }
}

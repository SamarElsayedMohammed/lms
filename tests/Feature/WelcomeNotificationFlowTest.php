<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WelcomeNotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => config('constants.SYSTEM_ROLES.USER')]);
    }

    public function test_welcome_notification_payload_contains_correct_arabic_brand(): void
    {
        $user = User::factory()->create([
            'email' => 'welcome_test@example.com',
            'name' => 'محمد أحمد',
        ]);

        $notification = new WelcomeNotification($user);
        $arrayData = $notification->toArray($user);

        $this->assertEquals('أهلاً بك في سكيلزو!', $arrayData['title']);
        $this->assertEquals('أهلاً بك في سكيلزو!', $arrayData['title_ar']);
        $this->assertStringNotContainsString('سكيلسو', $arrayData['title']);
        $this->assertStringNotContainsString('سكيلسو', $arrayData['title_ar']);
    }

    public function test_registration_flow_generates_welcome_notification_with_correct_brand(): void
    {
        $payload = [
            'name' => 'طالب جديد',
            'email' => 'new_student@example.com',
            'password' => 'Password123!',
            'confirm_password' => 'Password123!',
            'type' => 'email',
            'device_type' => 'web',
            'device_id' => 'device-flow-test-1',
        ];

        $response = $this->postJson('/api/user-signup', $payload);
        $response->assertStatus(200);

        $user = User::where('email', 'new_student@example.com')->first();
        $this->assertNotNull($user);

        // Verify the database notification was created
        $this->assertCount(1, $user->notifications);
        $notification = $user->notifications->first();
        $data = $notification->data;

        $this->assertEquals('أهلاً بك في سكيلزو!', $data['title']);
        $this->assertEquals('أهلاً بك في سكيلزو!', $data['title_ar']);
        $this->assertStringNotContainsString('سكيلسو', json_encode($data, JSON_UNESCAPED_UNICODE));

        // Authenticate as this user and fetch /api/notifications
        $loginResponse = $this->postJson('/api/user-login', [
            'type' => 'email',
            'email' => 'new_student@example.com',
            'password' => 'Password123!',
            'device_type' => 'web',
            'device_id' => 'device-flow-test-1',
        ]);
        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('data.token');

        $notifResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/notifications');

        $notifResponse->assertStatus(200);
        $notificationsList = $notifResponse->json('data.data') ?? $notifResponse->json('data') ?? [];
        $this->assertNotEmpty($notificationsList);

        $firstNotification = $notificationsList[0];
        $this->assertEquals('أهلاً بك في سكيلزو!', $firstNotification['title']);
        $this->assertStringNotContainsString('سكيلسو', $firstNotification['title']);
    }

    public function test_migration_updates_existing_stored_notifications(): void
    {
        $user = User::factory()->create([
            'email' => 'legacy_notif@example.com',
        ]);

        // Insert legacy notification row with old misspelled brand
        $notificationId = (string) \Illuminate\Support\Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => 'App\Notifications\WelcomeNotification',
            'notifiable_type' => 'App\Models\User',
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'title' => 'أهلاً بك في سكيلسو!',
                'title_ar' => 'أهلاً بك في سكيلسو!',
                'message' => 'أهلاً بك في منصتنا! ابدأ رحلتك التعليمية اليوم.',
                'message_ar' => 'أهلاً بك في منصتنا! ابدأ رحلتك التعليمية اليوم.',
                'action_url' => '/courses',
                'type' => 'welcome',
            ], JSON_UNESCAPED_UNICODE),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify it was inserted with old misspelled text
        $rowBefore = DB::table('notifications')->where('id', $notificationId)->first();
        $this->assertStringContainsString('سكيلسو', $rowBefore->data);

        // Run migration logic
        $migration = require database_path('migrations/2026_10_02_150000_update_brand_name_in_existing_notifications.php');
        $migration->up();

        // Verify the database record is updated to سكيلزو
        $rowAfter = DB::table('notifications')->where('id', $notificationId)->first();
        $this->assertStringNotContainsString('سكيلسو', $rowAfter->data);
        $this->assertStringContainsString('سكيلزو', $rowAfter->data);

        $decoded = json_decode($rowAfter->data, true);
        $this->assertEquals('أهلاً بك في سكيلزو!', $decoded['title']);
        $this->assertEquals('أهلاً بك في سكيلزو!', $decoded['title_ar']);
    }
}

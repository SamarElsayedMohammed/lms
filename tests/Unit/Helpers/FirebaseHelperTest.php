<?php

namespace Tests\Unit\Helpers;

use App\Helpers\FirebaseHelper;
use Tests\TestCase;

class FirebaseHelperTest extends TestCase
{
    public function test_send_method_exists()
    {
        $this->assertTrue(method_exists(FirebaseHelper::class, 'send'));
    }

    public function test_send_push_notification_method_exists()
    {
        $this->assertTrue(method_exists(FirebaseHelper::class, 'sendPushNotification'));
    }

    public function test_get_access_token_method_exists()
    {
        $this->assertTrue(method_exists(FirebaseHelper::class, 'getAccessToken'));
    }

    public function test_firebase_helper_class_is_instantiable()
    {
        $this->assertTrue(class_exists(FirebaseHelper::class));
    }

    public function test_send_method_handles_invalid_platform()
    {
        $platform = 'invalid';
        $registrationIds = 'test-token-123';
        $fcmMsg = ['title' => 'Test', 'body' => 'Test body'];
        $notification = [];

        $result = FirebaseHelper::send($platform, $registrationIds, $fcmMsg, $notification);

        $this->assertFalse($result);
    }
}

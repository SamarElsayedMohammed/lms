<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * Test POST /api/user-signup (mirrors Postman request).
 *
 * Diagnosed issues:
 * 1. firebase_token is required and must be a valid Firebase ID token (JWT from Google/Firebase Auth).
 *    Sending a fake value (e.g. "sd;lf',lfd") → 500 "Invalid Firebase token" (or "Firebase Configuration Error" if Firebase is not configured).
 * 2. For type=email the API expects "confirm_password" (not "password_confirmation").
 */
final class UserSignupApiTest extends TestCase
{
    /**
     * Same payload as Postman: POST user-signup with type google and invalid firebase_token.
     * Expect failure: firebase_token must be a valid Firebase ID token.
     */
    public function test_user_signup_postman_payload_with_invalid_firebase_token_fails(): void
    {
        $payload = [
            'name' => 'samar',
            'email' => 'samar@gmail.com',
            'password' => '123456789',
            'password_confirmation' => '123456789',
            'type' => 'google',
            'firebase_token' => "sd;lf',lfd",
        ];

        $response = $this->postJson('/api/user-signup', $payload);

        // Invalid Firebase token → 500; or Firebase not configured → 500 "Firebase Configuration Error"
        $response->assertStatus(500);
        $response->assertJsonPath('error', true);
        $message = $response->json('message');
        $this->assertTrue(
            $message === 'Invalid Firebase token' || $message === 'Firebase Configuration Error',
            "Expected 'Invalid Firebase token' or 'Firebase Configuration Error', got: " . ($message ?? 'null')
        );
    }
}

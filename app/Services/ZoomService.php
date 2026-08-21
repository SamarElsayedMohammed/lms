<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZoomService
{
    private $accountId;
    private $clientId;
    private $clientSecret;
    private $accessToken;

    public function __construct()
    {
        $this->accountId = (string) config('services.zoom.account_id');
        $this->clientId = (string) config('services.zoom.client_id');
        $this->clientSecret = (string) config('services.zoom.client_secret');
    }

    private function generateToken()
    {
        if (empty($this->accountId) || empty($this->clientId) || empty($this->clientSecret)) {
            return false;
        }

        try {
            $response = Http::connectTimeout(3)->timeout(10)->asForm()
                ->withHeaders([
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                ])
                ->post('https://zoom.us/oauth/token', [
                    'grant_type' => 'account_credentials',
                    'account_id' => $this->accountId,
                ]);

            if ($response->successful()) {
                $this->accessToken = $response->json('access_token');
                return true;
            }

            Log::error('Zoom Auth Error: ' . $response->body());
            return false;
        } catch (\Exception $e) {
            Log::error('Zoom Auth Exception: ' . $e->getMessage());
            return false;
        }
    }

    public function createMeeting(string $topic, string $startAt, int $durationMinutes, string $timezone = 'UTC')
    {
        if (!$this->generateToken()) {
            return [
                'success' => false,
                'message' => 'Zoom credentials are not set or invalid. Please check .env file.',
            ];
        }

        $response = Http::connectTimeout(3)->timeout(10)->withToken($this->accessToken)
            ->post('https://api.zoom.us/v2/users/me/meetings', [
                'topic' => $topic,
                'type' => 2, // Scheduled meeting
                'start_time' => date('Y-m-d\TH:i:s', strtotime($startAt)),
                'duration' => $durationMinutes,
                'timezone' => $timezone,
                'settings' => [
                    'host_video' => true,
                    'participant_video' => false,
                    'join_before_host' => false,
                    'mute_upon_entry' => true,
                    'watermark' => false,
                    'use_pmi' => false,
                    'approval_type' => 2, // No registration required
                    'audio' => 'both',
                    'auto_recording' => 'none',
                    'waiting_room' => true,
                ],
            ]);

        if ($response->successful()) {
            $data = $response->json();
            return [
                'success' => true,
                'data' => [
                    'meeting_id' => $data['id'],
                    'join_url' => $data['join_url'],
                    'password' => $data['password'] ?? '',
                ],
            ];
        }

        Log::error('Zoom Create Meeting Error: ' . $response->body());
        return [
            'success' => false,
            'message' => $response->json('message') ?? 'Unknown error occurred.',
        ];
    }
}

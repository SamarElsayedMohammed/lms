<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestBunnyWebhook extends Command
{
    protected $signature = 'skillso:test-bunny-webhook';
    protected $description = 'Simulate a Bunny Stream VideoFinished webhook payload';

    public function handle()
    {
        $this->info('Testing Bunny Webhook...');
        
        $libraryId = '123456';
        $videoGuid = 'test-video-guid-' . time();
        $secret = config('services.bunny.webhook_secret') ?: 'test-secret';
        
        $signature = hash('sha256', $libraryId . $secret);

        $payload = [
            'VideoLibraryId' => $libraryId,
            'VideoGuid'      => $videoGuid,
            'Status'         => 4,
        ];

        // Ensure config is temporarily set for testing if it's missing
        if (!config('services.bunny.webhook_secret')) {
            config(['services.bunny.webhook_secret' => $secret]);
        }

        // To properly test, we should hit our own application's route.
        // We will make a synchronous HTTP request to the local application.
        $url = url('/api/webhooks/bunny');
        
        $this->info("Sending payload to: $url");
        
        $response = Http::withHeaders([
            'Webhook-Signature' => $signature,
        ])->post($url, $payload);

        if ($response->successful()) {
            $this->info('Webhook received successfully. Response:');
            $this->line($response->body());
            
            if ($response->json('message') === 'Lecture not found') {
                $this->info('Success! The signature was valid, but the fake videoGuid was not found in the DB, which is expected for this test.');
            }
        } else {
            $this->error('Webhook failed with status: ' . $response->status());
            $this->line($response->body());
        }
    }
}

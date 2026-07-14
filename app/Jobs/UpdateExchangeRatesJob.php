<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\SupportedCurrency;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateExchangeRatesJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $apiKey = config('services.exchange_rate.api_key') ?? env('EXCHANGE_RATE_API_KEY');
        if (!$apiKey) {
            Log::warning('Exchange Rate API Key is missing.');
            return;
        }

        $apiUrl = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/EGP";

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)->get($apiUrl);

            if (!$response->successful()) {
                Log::error('Failed to fetch exchange rates: ' . $response->body());
                return;
            }

            $data = $response->json();
            $rates = $data['conversion_rates'] ?? [];

            if (empty($rates)) {
                Log::error('Exchange rate data is empty.');
                return;
            }

            $currencies = \App\Models\SupportedCurrency::all();

            foreach ($currencies as $currency) {
                $code = $currency->currency_code;
                if (isset($rates[$code])) {
                    // exchange_rate_to_egp = 1 / conversion_rate (e.g. 1/0.117 = 8.5)
                    $rateToEgp = 1 / $rates[$code];
                    
                    $currency->update([
                        'exchange_rate_to_egp' => $rateToEgp,
                        'last_updated_at' => now(),
                    ]);
                }
            }

            Log::info('Exchange rates updated successfully.');

        } catch (\Exception $e) {
            Log::error('Error updating exchange rates: ' . $e->getMessage());
        }
    }
}

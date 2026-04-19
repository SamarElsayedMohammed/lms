<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UpdateExchangeRates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'currencies:update-rates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and update currency exchange rates relative to EGP';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting exchange rate update...');
        \App\Jobs\UpdateExchangeRatesJob::dispatchSync();
        $this->info('Exchange rates updated successfully.');
    }
}

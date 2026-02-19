<?php

namespace App\Console\Commands;

use App\Services\AffiliateService;
use Illuminate\Console\Command;

class ReleaseAffiliateCommissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'affiliate:release-commissions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Release pending affiliate commissions whose available_date has passed';

    /**
     * Execute the console command.
     */
    public function handle(AffiliateService $affiliateService): int
    {
        $count = $affiliateService->releaseCommissions();

        $this->info("Released {$count} commissions");

        return 0;
    }
}

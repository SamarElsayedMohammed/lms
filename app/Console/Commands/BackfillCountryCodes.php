<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SubscriptionPlanPrice;
use Illuminate\Support\Facades\DB;

class BackfillCountryCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pricing:backfill-country-codes {--dry-run : Report affected row count and sample diff without writing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill null country_code and currency_code in subscription_plan_prices based on country_id relation';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        $this->info("Starting Backfill Migration for Subscription Plan Prices...");
        if ($isDryRun) {
            $this->warn("MODE: DRY RUN (no changes will be made)");
        } else {
            $this->info("MODE: EXECUTE (changes will be saved in a transaction)");
        }

        $query = SubscriptionPlanPrice::with('country')->whereNull('country_code');
        $count = (clone $query)->count();

        $this->info("Found {$count} rows with country_code IS NULL.");
        $this->line("--------------------------------------------------");

        if ($count === 0) {
            $this->info("Nothing to do. System is fully backfilled and idempotent.");
            return 0;
        }

        DB::beginTransaction();
        try {
            $updatedCount = 0;
            foreach ($query->get() as $priceRow) {
                $country = $priceRow->country;
                if (!$country) {
                    $this->error("Row ID {$priceRow->id}: Missing country relation (country_id = {$priceRow->country_id}). Skipping.");
                    continue;
                }

                $newCountryCode = $country->iso_code;
                $newCurrencyCode = $country->currency_code ?? 'USD';

                $this->line("Row ID {$priceRow->id} (Plan {$priceRow->plan_id}, Country {$country->name_en}):");
                $this->line("  - Current: country_code = NULL, currency_code = {$priceRow->currency_code}");
                $this->line("  - Target : country_code = {$newCountryCode}, currency_code = {$newCurrencyCode}");

                if (!$isDryRun) {
                    $priceRow->update([
                        'country_code' => $newCountryCode,
                        'currency_code' => $newCurrencyCode,
                    ]);
                }
                $updatedCount++;
            }

            if ($isDryRun) {
                DB::rollBack();
                $this->line("--------------------------------------------------");
                $this->warn("Dry Run completed. {$updatedCount} rows would be updated.");
                $this->warn("Run without --dry-run to apply changes.");
            } else {
                DB::commit();
                $this->line("--------------------------------------------------");
                $this->info("Execution completed. {$updatedCount} rows updated successfully.");
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("ERROR: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}

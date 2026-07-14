<?php

// To run this standalone script inside your Laravel environment:
// php scratch/backfill.php --dry-run
// php scratch/backfill.php --execute

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SubscriptionPlanPrice;
use Illuminate\Support\Facades\DB;

$isDryRun = !in_array('--execute', $argv);

echo "Starting Backfill Migration for Subscription Plan Prices...\n";
if ($isDryRun) {
    echo "MODE: DRY RUN (no changes will be made)\n\n";
} else {
    echo "MODE: EXECUTE (changes will be saved)\n\n";
}

$query = SubscriptionPlanPrice::with('country')->whereNull('country_code');
$count = (clone $query)->count();

echo "Found {$count} rows with country_code IS NULL.\n";
echo "--------------------------------------------------\n";

if ($count === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

DB::beginTransaction();
try {
    $updatedCount = 0;
    foreach ($query->get() as $priceRow) {
        $country = $priceRow->country;
        if (!$country) {
            echo "Row ID {$priceRow->id}: Missing country relation (country_id = {$priceRow->country_id}). Skipping.\n";
            continue;
        }

        $newCountryCode = $country->iso_code;
        $newCurrencyCode = $country->currency_code ?? 'USD';

        echo "Row ID {$priceRow->id} (Plan {$priceRow->plan_id}, Country {$country->name_en}):\n";
        echo "  - Current: country_code = NULL, currency_code = {$priceRow->currency_code}\n";
        echo "  - Target : country_code = {$newCountryCode}, currency_code = {$newCurrencyCode}\n";

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
        echo "\n--------------------------------------------------\n";
        echo "Dry Run completed. {$updatedCount} rows would be updated.\n";
        echo "Run with --execute to apply changes.\n";
    } else {
        DB::commit();
        echo "\n--------------------------------------------------\n";
        echo "Execution completed. {$updatedCount} rows updated successfully.\n";
    }
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

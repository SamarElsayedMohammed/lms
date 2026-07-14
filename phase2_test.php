<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Models\Country;
use Illuminate\Http\Request;
use App\Services\PricingService;

$planId = 1; // Assuming plan 1 for testing. Let's get the first plan.
$plan = SubscriptionPlan::first();
if (!$plan) {
    echo "No plans found.\n";
    exit;
}
echo "Plan ID: {$plan->id}, Default Price: {$plan->price}\n";

$overrides = SubscriptionPlanPrice::where('plan_id', $plan->id)->get();
echo "Overrides:\n";
foreach($overrides as $override) {
    echo " - Country ID: {$override->country_id}, Country Code: {$override->country_code}, Price: {$override->price}\n";
}

$pricingService = app(PricingService::class);

// Test EG
$priceEG = $pricingService->getPriceForCountry($plan, 'EG');
echo "Resolved for EG: " . json_encode($priceEG) . "\n";

// Test FR
$priceFR = $pricingService->getPriceForCountry($plan, 'FR');
echo "Resolved for FR: " . json_encode($priceFR) . "\n";

// Test US (assuming no override)
$priceUS = $pricingService->getPriceForCountry($plan, 'US');
echo "Resolved for US: " . json_encode($priceUS) . "\n";

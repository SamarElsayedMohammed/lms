<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = new App\Services\Payment\KashierCheckoutService();
$plan = new App\Models\SubscriptionPlan(['id' => 4]);
$user = new App\Models\User(['id' => 1]);
$res = $service->createCheckoutSession($plan, $user, 600, 'SAR');
print_r($res);

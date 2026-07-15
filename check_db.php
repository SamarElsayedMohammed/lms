<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$country = \App\Models\Country::where('iso_code', 'EG')->first();
echo "EG Country Data:\n";
echo json_encode($country, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$currency = \App\Models\SupportedCurrency::where('currency_code', 'EGP')->first();
echo "EGP Currency Data:\n";
echo json_encode($currency, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

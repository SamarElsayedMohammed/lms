<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$apiKey = \App\Services\CachingService::getSystemSettings('kashier_api_key');
$orderId = 'sub_37_159_1782574614';
$urls = [
    "https://api.kashier.io/payments/orders/{$orderId}",
    "https://test-api.kashier.io/payments/orders/{$orderId}"
];

foreach ($urls as $url) {
    echo "Testing with Bearer token: $url\n";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey", "Accept: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    echo "Response: $response\n\n";
}

$urls2 = [
    "https://api.kashier.io/payments/?merchantOrderId={$orderId}",
    "https://test-api.kashier.io/payments/?merchantOrderId={$orderId}"
];
foreach ($urls2 as $url) {
    echo "Testing with API key directly: $url\n";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $apiKey", "Accept: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    echo "Response: $response\n\n";
}

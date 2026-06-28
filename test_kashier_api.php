<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$apiKey = \App\Services\CachingService::getSystemSettings('kashier_api_key');
$orderId = 'sub_37_159_1782574614';
$urls = [
    "https://test-api.kashier.io/v1/transaction?merchantOrderId={$orderId}",
    "https://test-api.kashier.io/v1/transactions?merchantOrderId={$orderId}",
    "https://test-api.kashier.io/v1/transaction/order/{$orderId}",
    "https://test-api.kashier.io/payments/orders/{$orderId}"
];

foreach ($urls as $url) {
    echo "Testing: $url\n";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $apiKey", "Accept: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "HTTP Code: $httpCode\n";
    echo "Response: $response\n\n";
}

<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$apiKey = \App\Services\CachingService::getSystemSettings('kashier_api_key');
$transactionId = 'TX-2357767394'; // From user's screenshot
$urls = [
    "https://api.kashier.io/v1/transaction/{$transactionId}",
    "https://test-api.kashier.io/v1/transaction/{$transactionId}"
];

foreach ($urls as $url) {
    echo "Testing: $url\n";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $apiKey", "Accept: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    echo "Response: $response\n\n";
}

<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$request = Illuminate\Http\Request::create('/api/subscription/payment-methods', 'GET');
$controller = app(\App\Http\Controllers\API\SubscriptionApiController::class);
$response = $controller->getPaymentMethods($request);

echo $response->getContent();

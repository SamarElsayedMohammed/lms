<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$request = Illuminate\Http\Request::create('/api/reports/sales', 'GET', []);
// Bypass auth for test
$app->instance('middleware.disable', true);
$response = $kernel->handle($request);
echo "Sales Report Response:\n";
echo $response->getContent() . "\n\n";

$request2 = Illuminate\Http\Request::create('/api/reports/course', 'GET', []);
$response2 = $kernel->handle($request2);
echo "Course Report Response:\n";
echo $response2->getContent() . "\n";

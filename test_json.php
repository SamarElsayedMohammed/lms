<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$collection = collect([]);
echo "1: " . json_encode(['data' => $collection]) . "\n";
echo "2: " . json_encode(['data' => $collection->values()]) . "\n";
echo "3: " . json_encode(response()->json(['data' => $collection->values()])->getData()) . "\n";

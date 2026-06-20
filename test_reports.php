<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- Student Report Data ---\n";
$completedOrdersCount = \App\Models\Order::where('status', 'completed')->count();
echo "Completed Orders (Courses): " . $completedOrdersCount . "\n";
$usersWithCompletedOrders = \App\Models\User::whereHas('orders', fn($q) => $q->where('status', 'completed'))->count();
echo "Students with completed orders: " . $usersWithCompletedOrders . "\n";

echo "\n--- Subscriptions Report Data ---\n";
$subsCount = \App\Models\Subscription::count();
echo "Total Subscriptions: " . $subsCount . "\n";
$subsPayments = \App\Models\SubscriptionPayment::where('status', 'completed')->count();
echo "Completed SubPayments: " . $subsPayments . "\n";

echo "\n--- Revenue Report Data ---\n";
$ordersRev = \App\Models\Order::where('status', 'completed')->sum('final_price');
echo "Course Orders Revenue: " . $ordersRev . "\n";
$subsRev = \App\Models\SubscriptionPayment::where('status', 'completed')->sum('final_amount');
echo "Subscriptions Revenue: " . $subsRev . "\n";


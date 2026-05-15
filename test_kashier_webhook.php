<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\KashierController;

// Create a test user
$user = User::first();
if (!$user) {
    $user = User::factory()->create();
}

echo "Testing user: {$user->id}\n";
$orderId = 'wlt_' . $user->id . '_' . time();

// Mock request
$request = Request::create('/webhooks/kashier', 'POST', [
    'merchantOrderId' => $orderId,
    'paymentStatus' => 'SUCCESS',
    'amount' => 100,
    'transactionId' => 'test_txn_' . time(),
    'signature' => 'mock_signature'
]);

// Create anonymous class to mock KashierCheckoutService
$mockKashier = new class extends \App\Services\Payment\KashierCheckoutService {
    public function initiate(\App\Models\Order $order, array $options = []): array { return []; }
    public function createCheckoutSession(\App\Models\SubscriptionPlan $plan, User $user, float $amount): array { return []; }
    public function createWalletTopUpSession(User $user, float $amount): array { return []; }
    public function createWebinarCheckoutSession(int $webinarId, User $user, float $amount): array { return []; }
    public function verifyPayment(array $data): bool { return true; }
    public function getPaymentStatus(string $transactionId): string { return 'success'; }
};

$mockSubscription = app(\App\Services\SubscriptionService::class);

$controller = new KashierController($mockKashier, $mockSubscription);

try {
    $response = $controller->handleWebhook($request);
    echo "Response status: " . $response->getStatusCode() . "\n";
    if ($response->isRedirection()) {
        echo "Redirected to: " . $response->headers->get('Location') . "\n";
    } else {
        echo "Response body: " . $response->getContent() . "\n";
    }
    
    // Check wallet balance
    $user->refresh();
    echo "New wallet balance: " . $user->wallet_balance . "\n";
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

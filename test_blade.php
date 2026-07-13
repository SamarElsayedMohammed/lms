<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $html = view('emails.general-notification', [
        'notificationTitle' => 'Test Title',
        'notificationContent' => 'Test Content',
        'imageUrl' => null,
        'greeting' => 'مرحباً،'
    ])->render();
    echo "Success!";
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
} catch (\Throwable $e) {
    echo "Throwable: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}

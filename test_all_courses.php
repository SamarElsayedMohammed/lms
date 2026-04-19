<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$courses = App\Models\Course\Course::select('id', 'title', 'is_active', 'status', 'approval_status')->get();
echo "Total courses: " . $courses->count() . "\n";
foreach($courses as $c) {
    echo "ID: $c->id | Title: $c->title | Active: $c->is_active | Status: $c->status | Approval: $c->approval_status\n";
}

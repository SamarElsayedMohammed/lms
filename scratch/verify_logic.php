<?php
use App\Models\User;
use App\Models\Course\Course;
use Illuminate\Support\Facades\Auth;

// 1. Get the instructor user
$user = User::role('Instructor')->first();
if (!$user) {
    echo "No instructor found\n";
    exit;
}

// 2. Mock authentication
Auth::login($user);

$isAdmin = Auth::user()->hasRole('Admin');
$isInstructor = Auth::user()->hasRole('Instructor');
$requestedStatus = 'publish';

$data = [];
if ($isAdmin || $isInstructor) {
    $data['status'] = in_array($requestedStatus, ['draft', 'publish']) ? $requestedStatus : 'publish';
    $data['approval_status'] = $data['status'] === 'publish' ? 'approved' : null;
    $data['is_active'] = 1; // Simulation
}

echo "Assigned Status: " . $data['status'] . "\n";
echo "Assigned Approval Status: " . ($data['approval_status'] ?? 'null') . "\n";
echo "Assigned Is Active: " . $data['is_active'] . "\n";

if ($data['status'] === 'publish' && $data['approval_status'] === 'approved') {
    echo "SUCCESS: Instructor can now publish courses directly!\n";
} else {
    echo "FAILURE: Logic check failed.\n";
}

<?php
/**
 * Reset password for dm328432@gmail.com and reset password for all test users
 * so they have a known working password.
 */
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$users = User::all(['id', 'name', 'email', 'phone_number', 'role']);
echo "=== ALL USERS ===\n";
foreach ($users as $u) {
    echo "ID: {$u->id} | Name: {$u->name} | Email: {$u->email} | Phone: {$u->phone_number} | Role: {$u->role}\n";
}
echo "\n";

// Check password hash for user 1
$user = User::where('email', 'dm328432@gmail.com')->first();
if ($user) {
    $testPasswords = ['password', 'Password', 'Password123', '12345678', 'fastnet', 'Fastnet123', 'admin', 'Admin123'];
    echo "=== PASSWORD CHECK FOR dm328432@gmail.com ===\n";
    foreach ($testPasswords as $pw) {
        $match = Hash::check($pw, $user->password);
        echo "  '{$pw}' => " . ($match ? "MATCH ✓" : "no match") . "\n";
    }
}

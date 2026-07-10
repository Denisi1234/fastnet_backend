<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\LodgeServiceRequestController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\WishlistController;
use Illuminate\Support\Facades\Route;

// Public Auth routes
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Password Reset (public)
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
});

// Public Property / Lodge discovery routes
Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/{id}', [PropertyController::class, 'show']);

// Property reviews (GET is public)
Route::get('/properties/{id}/reviews', [ReviewController::class, 'index']);

// Protected routes (require Sanctum API token authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Booking routes
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/bookings', [BookingController::class, 'index']);
    
    // Property listing (Hosts/Owners only)
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::post('/upload', [PropertyController::class, 'upload']);
    
    // Payment checkout initiation
    Route::post('/payments/checkout', [PaymentController::class, 'checkout']);

    // Message routes
    Route::get('/messages/threads', [MessageController::class, 'threads']);
    Route::get('/messages/{partnerId}', [MessageController::class, 'index']);
    Route::post('/messages', [MessageController::class, 'store']);

    // Ticket routes
    Route::get('/tickets', [TicketController::class, 'index']);
    Route::post('/tickets', [TicketController::class, 'store']);
    Route::post('/tickets/{id}/messages', [TicketController::class, 'sendMessage']);
    Route::patch('/tickets/{id}/status', [TicketController::class, 'updateStatus']);

    // Admin routes
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::patch('/admin/users/{id}/status', [AdminController::class, 'updateUserStatus']);
    Route::post('/admin/users', [AdminController::class, 'addUser']);
    Route::get('/admin/properties', [AdminController::class, 'properties']);
    Route::patch('/admin/properties/{id}/status', [AdminController::class, 'updatePropertyStatus']);

    // Staff routes
    Route::get('/staff', [StaffController::class, 'index']);
    Route::post('/staff', [StaffController::class, 'store']);
    Route::patch('/staff/{id}', [StaffController::class, 'update']);
    Route::delete('/staff/{id}', [StaffController::class, 'destroy']);

    // Lodge Service Request routes
    Route::get('/lodge-requests', [LodgeServiceRequestController::class, 'index']);
    Route::post('/lodge-requests', [LodgeServiceRequestController::class, 'store']);
    Route::patch('/lodge-requests/{id}/status', [LodgeServiceRequestController::class, 'updateStatus']);
    Route::delete('/lodge-requests/{id}', [LodgeServiceRequestController::class, 'destroy']);

    // Real-time temporary inventory lock/unlock routes
    Route::post('/bookings/lock', [BookingController::class, 'lockRoom']);
    Route::post('/bookings/unlock', [BookingController::class, 'unlockRoom']);

    // Cancel booking
    Route::delete('/bookings/{id}', [BookingController::class, 'cancel']);

    // Reviews
    Route::post('/reviews', [ReviewController::class, 'store']);

    // Wishlist
    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{property_id}', [WishlistController::class, 'destroy']);

    // Admin bookings
    Route::get('/admin/bookings', [AdminController::class, 'bookings']);
    Route::patch('/admin/bookings/{id}/status', [AdminController::class, 'updateBookingStatus']);

    // Admin payments
    Route::get('/admin/payments', [AdminController::class, 'payments']);
});

// Payment webhook (Public)
Route::post('/payments/webhook', [PaymentController::class, 'webhook']);

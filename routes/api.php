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
use App\Http\Controllers\NewsletterSubscriptionController;
use Illuminate\Support\Facades\Route;

// Public Auth routes
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/easy-auth', [AuthController::class, 'easyAuth']);
});

// Password Reset & OTP Verification (public)
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/send-otp', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/resend-code', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/verify-otp', [PasswordResetController::class, 'verifyOtp']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
});

// Public Property / Lodge discovery & Receipt / Notification routes
Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/search/suggestions', [PropertyController::class, 'suggestions']);
Route::get('/properties/{id}', [PropertyController::class, 'show']);
Route::get('/properties/{id}/images', [PropertyController::class, 'getImages']);
Route::get('/properties/{propertyId}/rooms', [PropertyController::class, 'getRooms']);
Route::post('/receipts/generate', [BookingController::class, 'generateReceipt']);
Route::get('/notifications/preferences', [\App\Http\Controllers\NotificationPreferenceController::class, 'getPreferences']);
Route::post('/notifications/preferences', [\App\Http\Controllers\NotificationPreferenceController::class, 'updatePreferences']);
Route::get('/travel/preferences', [\App\Http\Controllers\TravelPreferenceController::class, 'getPreferences']);
Route::post('/travel/preferences', [\App\Http\Controllers\TravelPreferenceController::class, 'updatePreferences']);
Route::get('/support/help-centre', [\App\Http\Controllers\SupportController::class, 'getHelpCentreData']);
Route::get('/user/personal-details', [\App\Http\Controllers\PersonalDetailsController::class, 'getDetails']);
Route::post('/user/personal-details', [\App\Http\Controllers\PersonalDetailsController::class, 'updateDetails']);
Route::get('/alerts', [\App\Http\Controllers\AlertController::class, 'index']);
Route::post('/alerts', [\App\Http\Controllers\AlertController::class, 'store']);
Route::delete('/alerts/{id}', [\App\Http\Controllers\AlertController::class, 'destroy']);
Route::get('/currencies', [\App\Http\Controllers\CurrencyController::class, 'index']);
Route::get('/map-config', function () {
    $token = env('MAPBOX_TOKEN', env('MAPBOX_ACCESS_TOKEN', env('MAPBOX_API_KEY', '')));
    return response()->json([
        'mapbox_token' => $token ?: 'pk.eyJ1IjoiZmFzdG5ldHN0YXlzIiwiYSI6ImNtMGY5YWFxeDAxZG0ydnFzOWd6ZWxsNGkifQ.demo',
        'mapbox_style' => env('MAPBOX_STYLE', 'mapbox://styles/mapbox/streets-v12'),
        'style' => env('MAPBOX_STYLE', 'mapbox://styles/mapbox/streets-v12')
    ]);
});

// Property reviews (GET is public)
Route::get('/properties/{id}/reviews', [ReviewController::class, 'index']);

// Newsletter subscription (Public)
Route::post('/subscribe', [NewsletterSubscriptionController::class, 'subscribe']);

// Accessibility Feedback (Public)
Route::post('/feedback/accessibility', [\App\Http\Controllers\AccessibilityFeedbackController::class, 'store']);

// Booking calculation, revalidation & creation (Public)
Route::post('/bookings/calculate', [BookingController::class, 'calculate']);
Route::get('/bookings/calculate', [BookingController::class, 'calculate']);
Route::get('/bookings/revalidate', [BookingController::class, 'revalidate']);
Route::post('/bookings/revalidate', [BookingController::class, 'revalidate']);
Route::post('/bookings/create', [BookingController::class, 'store']);

// AzamPay Payment gateway routes (Public)
Route::post('/payments/checkout', [PaymentController::class, 'checkout']);
Route::post('/payments/webhook', [PaymentController::class, 'webhook']);
Route::get('/payments/status/{codeOrId}', [PaymentController::class, 'status']);
// File upload & Room management (Public)
Route::post('/upload', [PropertyController::class, 'upload']);
Route::put('/rooms/{id}', [PropertyController::class, 'updateRoom']);
Route::delete('/rooms/{id}', [PropertyController::class, 'destroyRoom']);

// Protected routes (require Sanctum API token authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/profile/photo', [AuthController::class, 'uploadProfilePhoto']);
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Booking routes
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/bookings', [BookingController::class, 'index']);
    
    // Property listing (Hosts/Owners only)
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::put('/properties/{id}', [PropertyController::class, 'update']);
    Route::patch('/properties/{id}', [PropertyController::class, 'update']);
    Route::post('/properties/{id}/generate-description', [PropertyController::class, 'generateDescription']);
    Route::post('/properties/{propertyId}/rooms', [PropertyController::class, 'storeRoom']);
    
    // Payment checkout initiation
    Route::post('/payments/checkout', [PaymentController::class, 'checkout']);

    // Message routes
    Route::get('/messages/threads', [MessageController::class, 'threads']);
    Route::get('/messages/{partnerId}', [MessageController::class, 'index']);
    Route::post('/messages', [MessageController::class, 'store']);

    // Ticket routes
    Route::get('/tickets', [TicketController::class, 'index']);
    Route::get('/tickets/{id}', [TicketController::class, 'show']);
    Route::post('/tickets', [TicketController::class, 'store']);
    Route::post('/tickets/{id}/messages', [TicketController::class, 'sendMessage']);
    Route::post('/tickets/{id}/reply', [TicketController::class, 'sendMessage']);
    Route::patch('/tickets/{id}/status', [TicketController::class, 'updateStatus']);

    // Admin routes
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::patch('/admin/users/{id}/status', [AdminController::class, 'updateUserStatus']);
    Route::post('/admin/users', [AdminController::class, 'addUser']);
    Route::get('/admin/dashboard-stats', [\App\Http\Controllers\AdminController::class, 'dashboardStats']);
    Route::get('/admin/owners/financial-summary', [AdminController::class, 'ownerFinancialSummary']);
    Route::get('/admin/owners/{id}/financial-profile', [AdminController::class, 'ownerFinancialProfile']);
    Route::get('/admin/properties', [AdminController::class, 'properties']);
    Route::patch('/admin/properties/{id}/status', [AdminController::class, 'updatePropertyStatus']);
    Route::get('/admin/reviews', [ReviewController::class, 'adminIndex']);

    // Verification Workflow Routes
    Route::post('/verification/owner', [\App\Http\Controllers\VerificationController::class, 'submitOwnerVerification']);
    Route::get('/verification/owner/{userId?}', [\App\Http\Controllers\VerificationController::class, 'getOwnerVerification']);
    Route::post('/admin/verification/owner/{ownerId}', [\App\Http\Controllers\VerificationController::class, 'reviewOwner']);
    Route::post('/verification/lodge/{propertyId}', [\App\Http\Controllers\VerificationController::class, 'submitLodgeVerification']);
    Route::post('/admin/verification/lodge/{propertyId}', [\App\Http\Controllers\VerificationController::class, 'reviewLodge']);
    Route::get('/admin/verification/summary', [\App\Http\Controllers\VerificationController::class, 'adminVerificationSummary']);

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

    // Finance & Payout Reports
    Route::get('/finance/overview', [\App\Http\Controllers\FinanceController::class, 'overview']);
    Route::get('/finance/ledger', [\App\Http\Controllers\FinanceController::class, 'ledger']);

    // Owner & Admin Payout Management System
    Route::get('/payouts', [\App\Http\Controllers\PayoutController::class, 'index']);
    Route::get('/payouts/summary', [\App\Http\Controllers\PayoutController::class, 'summary']);
    Route::post('/payouts/request', [\App\Http\Controllers\PayoutController::class, 'requestPayout']);
    Route::patch('/payouts/{id}/status', [\App\Http\Controllers\PayoutController::class, 'updateStatus']);

    // Admin bookings
    Route::get('/admin/bookings', [AdminController::class, 'bookings']);
    Route::patch('/admin/bookings/{id}/status', [AdminController::class, 'updateBookingStatus']);

    // Admin payments
    Route::get('/admin/payments', [AdminController::class, 'payments']);
});

// Payment webhook (Public)
Route::post('/payments/webhook', [PaymentController::class, 'webhook']);

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\FootballTipController;
use App\Http\Controllers\Api\AlmaxPredictionController;
use App\Http\Controllers\Api\RecentWinController;
use App\Http\Controllers\Api\TestimonialController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\WebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api (configured in bootstrap/app.php).
| Rate limiting is applied per group.
| Admin routes require a valid Sanctum admin token (role:admin ability).
| User routes require a valid Sanctum user token (role:user ability).
|
*/

// ─── Health check ──────────────────────────────────────────────────────────
Route::get('/health', fn () => response()->json(['status' => 'ok', 'timestamp' => now()->toISOString()]));

// ─── GitHub deployment webhook (no auth, HMAC-verified inside controller) ──
Route::post('/webhook/github', [WebhookController::class, 'github'])
    ->middleware('throttle:20,1');

// ─── Auth (admin) ───────────────────────────────────────────────────────────
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/change-password', [AuthController::class, 'changePassword'])
        ->middleware('auth.admin');
});

// ─── Users (public registration/login, admin management) ───────────────────
Route::prefix('users')->group(function () {
    // Public
    Route::post('/', [UserController::class, 'register'])
        ->middleware('throttle:auth');
    Route::post('/login', [UserController::class, 'login'])
        ->middleware('throttle:auth');
    Route::get('/by-phone/{phone}', [UserController::class, 'findByPhone'])
        ->middleware('throttle:api');

    // Admin
    Route::get('/', [UserController::class, 'index'])
        ->middleware('auth.admin');
    Route::delete('/{id}', [UserController::class, 'destroy'])
        ->middleware('auth.admin');
});

// ─── Groups (VIP packages) ──────────────────────────────────────────────────
Route::prefix('groups')->group(function () {
    // Public: active packages only (special hidden until admin sets a price)
    Route::get('/', [GroupController::class, 'index'])
        ->middleware('throttle:api');

    // Admin: all packages including inactive/unpriced ones
    Route::get('/admin', [GroupController::class, 'indexAdmin'])
        ->middleware('auth.admin');

    // Admin: create a new package
    Route::post('/', [GroupController::class, 'store'])
        ->middleware('auth.admin');

    // Admin: update price, betslip, special_price, special_odds, is_active
    Route::patch('/{id}', [GroupController::class, 'update'])
        ->middleware('auth.admin');

    // Admin: delete a package (blocked if subscriptions exist)
    Route::delete('/{id}', [GroupController::class, 'destroy'])
        ->middleware('auth.admin');
});

// ─── Subscriptions ──────────────────────────────────────────────────────────
Route::prefix('subscriptions')->group(function () {
    // Public
    Route::post('/', [SubscriptionController::class, 'store'])
        ->middleware('throttle:subscription');
    Route::get('/user/{userId}', [SubscriptionController::class, 'forUser'])
        ->middleware('throttle:api');
    Route::get('/{id}/payment-status', [SubscriptionController::class, 'paymentStatus'])
        ->middleware('throttle:api');

    // Admin
    Route::get('/', [SubscriptionController::class, 'index'])
        ->middleware('auth.admin');
    Route::patch('/{id}', [SubscriptionController::class, 'update'])
        ->middleware('auth.admin');
    Route::post('/{id}/renew', [SubscriptionController::class, 'renew'])
        ->middleware('auth.admin');
    Route::delete('/{id}', [SubscriptionController::class, 'destroy'])
        ->middleware('auth.admin');

    // Public: user submits their own transaction ID when STK push didn't auto-confirm
    Route::post('/{id}/submit-transaction', [SubscriptionController::class, 'submitTransaction'])
        ->middleware('throttle:api');
});

// ─── Payments ───────────────────────────────────────────────────────────────
// No auth and no throttle: payment providers must be able to deliver callbacks reliably.
Route::match(['get', 'post'], '/payments/webhook', [PaymentController::class, 'webhook']);

Route::prefix('payments')->middleware('auth.admin')->group(function () {
    Route::get('/report', [PaymentController::class, 'report']);
    Route::post('/reconcile', [PaymentController::class, 'reconcile']);
    Route::get('/', [PaymentController::class, 'index']);
    Route::get('/{id}', [PaymentController::class, 'show']);
});

// ─── Football Tips ──────────────────────────────────────────────────────────
Route::prefix('football-tips')->group(function () {
    Route::get('/', [FootballTipController::class, 'index'])
        ->middleware('throttle:api');
    Route::post('/', [FootballTipController::class, 'store'])
        ->middleware('auth.admin');
    Route::put('/{id}', [FootballTipController::class, 'update'])
        ->middleware('auth.admin');
    Route::delete('/{id}', [FootballTipController::class, 'destroy'])
        ->middleware('auth.admin');
});

// ─── Almax Predictions ───────────────────────────────────────────────────────
Route::prefix('almax-predictions')->group(function () {
    Route::get('/', [AlmaxPredictionController::class, 'index'])
        ->middleware('throttle:api');
    Route::post('/', [AlmaxPredictionController::class, 'store'])
        ->middleware('auth.admin');
    Route::patch('/{id}', [AlmaxPredictionController::class, 'update'])
        ->middleware('auth.admin');
    Route::delete('/{id}', [AlmaxPredictionController::class, 'destroy'])
        ->middleware('auth.admin');
});

// ─── Recent Wins ────────────────────────────────────────────────────────────
Route::prefix('recent-wins')->group(function () {
    Route::get('/', [RecentWinController::class, 'index'])
        ->middleware('throttle:api');
    Route::post('/', [RecentWinController::class, 'store'])
        ->middleware('auth.admin');
    Route::put('/{id}', [RecentWinController::class, 'update'])
        ->middleware('auth.admin');
    Route::delete('/{id}', [RecentWinController::class, 'destroy'])
        ->middleware('auth.admin');
});

// ─── Testimonials ───────────────────────────────────────────────────────────
Route::prefix('testimonials')->group(function () {
    Route::get('/', [TestimonialController::class, 'index'])
        ->middleware('throttle:api');
    Route::post('/', [TestimonialController::class, 'store'])
        ->middleware('auth.admin');
    Route::put('/{id}', [TestimonialController::class, 'update'])
        ->middleware('auth.admin');
    Route::delete('/{id}', [TestimonialController::class, 'destroy'])
        ->middleware('auth.admin');
});

// ─── Config ─────────────────────────────────────────────────────────────────
Route::prefix('config')->group(function () {
    Route::get('/free-odd2', [ConfigController::class, 'getFreeOdd2'])
        ->middleware('throttle:api');
    Route::put('/free-odd2', [ConfigController::class, 'updateFreeOdd2'])
        ->middleware('auth.admin');
    Route::get('/vip-config', [ConfigController::class, 'getVipConfig'])
        ->middleware('throttle:api');
    Route::put('/vip-config', [ConfigController::class, 'updateVipConfig'])
        ->middleware('auth.admin');
});

// ─── Notifications ───────────────────────────────────────────────────────────
Route::prefix('notifications')->group(function () {
    Route::post('/status-check', [NotificationController::class, 'statusCheck'])
        ->middleware('throttle:api');
    Route::get('/', [NotificationController::class, 'index'])
        ->middleware('auth.admin');
    Route::get('/unread-count', [NotificationController::class, 'unreadCount'])
        ->middleware('auth.admin');
    Route::patch('/{id}/read', [NotificationController::class, 'markRead'])
        ->middleware('auth.admin');
    Route::patch('/read-all', [NotificationController::class, 'markAllRead'])
        ->middleware('auth.admin');
});

// ─── Livescores (removed — returns 404 matching Node.js behaviour) ──────────
Route::any('/livescores', fn () => response()->json(['error' => 'Not found'], 404));

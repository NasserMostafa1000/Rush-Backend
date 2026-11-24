<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureDriver;
use App\Presentation\Http\Controllers\Api\Address\SavedAddressController;
use App\Presentation\Http\Controllers\Api\Admin\DriverVerificationController;
use App\Presentation\Http\Controllers\Api\Admin\PromoCodeController;
use App\Presentation\Http\Controllers\Api\Auth\AuthController;
use App\Presentation\Http\Controllers\Api\Driver\DriverController;
use App\Presentation\Http\Controllers\Api\Driver\DriverOrderController;
use App\Presentation\Http\Controllers\Api\Driver\TransportationController;
use App\Presentation\Http\Controllers\Api\Loyalty\LoyaltyController;
use App\Presentation\Http\Controllers\Api\Notification\NotificationController;
use App\Presentation\Http\Controllers\Api\Order\OrderController;
use App\Presentation\Http\Controllers\Api\Payment\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/webhooks/stripe', StripeWebhookController::class);

    Route::get('/transportations', [TransportationController::class, 'index']);

    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::patch('/language', [AuthController::class, 'updateLanguage']);
            Route::match(['patch', 'post'], '/profile', [AuthController::class, 'updateProfile']);
            Route::patch('/location', [AuthController::class, 'updateLocation']);
        });
    });

    Route::get('/loyalty', [LoyaltyController::class, 'info']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::get('/orders/{order}/payment-status', [OrderController::class, 'paymentStatus']);
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
        Route::post('/orders/{order}/rate', [OrderController::class, 'rate']);

        Route::get('/addresses', [SavedAddressController::class, 'index']);
        Route::post('/addresses', [SavedAddressController::class, 'store']);
        Route::patch('/addresses/{address}', [SavedAddressController::class, 'update']);
        Route::delete('/addresses/{address}', [SavedAddressController::class, 'destroy']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

        Route::middleware(EnsureDriver::class)->prefix('driver')->group(function () {
            Route::get('/profile', [DriverController::class, 'show']);
            Route::patch('/profile', [DriverController::class, 'updateProfile']);
            Route::post('/documents', [DriverController::class, 'uploadDocuments']);
            Route::match(['put', 'post'], '/vehicle', [DriverController::class, 'upsertVehicle']);
            Route::post('/submit', [DriverController::class, 'submit']);

            Route::patch('/online', [DriverOrderController::class, 'setOnline']);
            Route::get('/orders/available', [DriverOrderController::class, 'available']);
            Route::get('/orders', [DriverOrderController::class, 'index']);
            Route::get('/orders/{order}', [DriverOrderController::class, 'show']);
            Route::post('/orders/{order}/accept', [DriverOrderController::class, 'accept']);
            Route::post('/orders/{order}/reject', [DriverOrderController::class, 'reject']);
            Route::post('/orders/{order}/picked-up', [DriverOrderController::class, 'pickedUp']);
            Route::post('/orders/{order}/delivered', [DriverOrderController::class, 'delivered']);
            Route::get('/earnings', [DriverOrderController::class, 'earnings']);
        });

        Route::middleware(EnsureAdmin::class)->prefix('admin')->group(function () {
            Route::get('/promo-codes', [PromoCodeController::class, 'index']);
            Route::post('/promo-codes', [PromoCodeController::class, 'store']);
            Route::put('/loyalty', [LoyaltyController::class, 'update']);
            Route::post('/drivers/{user}/verify', [DriverVerificationController::class, 'update']);
        });
    });
});

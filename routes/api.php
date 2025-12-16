<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\ClientAppointmentController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TelegramSetupController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\MercadoPagoWebhookController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SuperAdmin\UserManagementController;
use App\Http\Controllers\Api\SuperAdmin\MercadoPagoController;
use App\Http\Controllers\Api\SuperAdmin\ActivityLogController;
use Illuminate\Support\Facades\Route;

Route::middleware('cors')->group(function () {
Route::get('/services', [ServiceController::class, 'index']);
Route::get('/availability', AvailabilityController::class);
Route::get('/companies/{company:slug}', [CompanyController::class, 'publicShow']);
Route::post('/mercadopago/webhook', MercadoPagoWebhookController::class);
});

Route::post('/login', [AuthController::class, 'login']);
Route::middleware('cors')->post('/register', [AuthController::class, 'register']);

Route::prefix('clients')->middleware('cors')->group(function () {
    Route::post('/register', [ClientAuthController::class, 'register']);
    Route::post('/login', [ClientAuthController::class, 'login']);
    Route::post('/login/google', [ClientAuthController::class, 'loginWithGoogle']);

    Route::middleware(['auth:sanctum', 'abilities:client'])->group(function () {
        Route::post('/logout', [ClientAuthController::class, 'logout']);
        Route::get('/me', [ClientAuthController::class, 'me']);
        Route::post('/profile', [ProfileController::class, 'updateClient']);
        Route::get('/appointments', [ClientAppointmentController::class, 'index']);
        Route::put('/appointments/{appointment}', [ClientAppointmentController::class, 'update']);
        Route::post('/appointments/{appointment}/cancel', [ClientAppointmentController::class, 'cancel']);
    });
});

Route::middleware(['cors' ,'auth:sanctum', 'ability:provider,admin', 'subscription.active' ])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->withoutMiddleware('subscription.active');
    Route::get('/me', [AuthController::class, 'me']);

    Route::post('/services', [ServiceController::class, 'store']);
    Route::put('/services/{service}', [ServiceController::class, 'update']);
    Route::delete('/services/{service}', [ServiceController::class, 'destroy']);

    Route::get('/company', [CompanyController::class, 'show']);
    Route::post('/company', [CompanyController::class, 'update']);

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);

    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::put('/appointments/{appointment}', [AppointmentController::class, 'update']);
    Route::post('/appointments/{appointment}/status', [AppointmentController::class, 'status']);
    Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy']);

    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAll']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);

    Route::post('/company/telegram/link', [TelegramSetupController::class, 'createLink']);
    Route::post('/company/telegram/link/verify', [TelegramSetupController::class, 'verifyLink']);
    Route::post('/profile', [ProfileController::class, 'updateProvider']);
    Route::get('/subscription', [SubscriptionController::class, 'show'])->withoutMiddleware('subscription.active');
    Route::post('/subscription/checkout', [SubscriptionController::class, 'checkout'])->withoutMiddleware('subscription.active');
});

Route::prefix('admin')->middleware(['cors', 'auth:sanctum', 'abilities:admin'])->group(function () {
    Route::get('/providers', [UserManagementController::class, 'index']);
    Route::get('/plans', [UserManagementController::class, 'plans']);
    Route::post('/providers/{company}/subscription', [UserManagementController::class, 'updateSubscription']);
    Route::get('/mercado-pago/subscriptions', [MercadoPagoController::class, 'index']);
    Route::post('/mercado-pago/plans/sync', [MercadoPagoController::class, 'syncPlans']);
    Route::get('/logs', [ActivityLogController::class, 'index']);
});
Route::post('/appointments', [AppointmentController::class, 'store'])->middleware(['cors', 'auth:sanctum']);

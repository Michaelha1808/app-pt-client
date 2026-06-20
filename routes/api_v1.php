<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FoodController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::get('/google', [AuthController::class, 'googleRedirect']);
    Route::get('/google/callback', [AuthController::class, 'googleCallback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// Food analysis — public (guest được phép), rate limit 10/min
Route::middleware('throttle:10,1')->post('/food/analyze', [FoodController::class, 'analyze']);

// Food log — auth required
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/food/log', [FoodController::class, 'log']);
    Route::delete('/food/log/{log}', [FoodController::class, 'deleteLog']);
    Route::get('/food/today', [FoodController::class, 'todayStats']);
    Route::get('/food/history', [FoodController::class, 'history']);
});

Route::middleware('auth:sanctum')->prefix('user')->group(function () {
    Route::get('/profile', [UserController::class, 'profile']);
    Route::patch('/profile', [UserController::class, 'updateProfile']);
    Route::post('/avatar', [UserController::class, 'uploadAvatar']);
    Route::delete('/avatar', [UserController::class, 'deleteAvatar']);
    Route::post('/change-password', [UserController::class, 'changePassword']);
});

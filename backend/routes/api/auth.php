<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('/sanctum/csrf-cookie', function () {
        return response()->json(['ok' => true]);
    });

    Route::get('/entrar', [HomeController::class, 'index']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:password-reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AuthController::class, 'updateProfile'])->middleware('throttle:10,1');
        Route::put('/me/password', [AuthController::class, 'updatePassword'])->middleware('throttle:5,1');
        Route::get('/dashboard', [HomeController::class, 'dashboard']);
        Route::get('/branding', [BrandingController::class, 'show']);
    });
});

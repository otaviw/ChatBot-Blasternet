<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('/sanctum/csrf-cookie', function () {
        return response()->json(['ok' => true]);
    });

    Route::get('/entrar', [HomeController::class, 'index']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AuthController::class, 'updateProfile'])->middleware('throttle:10,1');
        Route::get('/dashboard', [HomeController::class, 'dashboard']);
    });
});

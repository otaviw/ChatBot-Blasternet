<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->middleware('throttle:inbox-read');
    Route::get('/notifications/unread-counts', [NotificationController::class, 'unreadCounts'])
        ->middleware('throttle:inbox-read');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
        ->middleware('throttle:bot-write');
    Route::post('/notifications/read-by-reference', [NotificationController::class, 'markReadByReference'])
        ->middleware('throttle:bot-write');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->middleware('throttle:bot-write');
    Route::delete('/notifications/bulk', [NotificationController::class, 'destroyMany'])
        ->middleware('throttle:bot-write');
});

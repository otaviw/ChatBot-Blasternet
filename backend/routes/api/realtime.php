<?php

use App\Http\Controllers\RealtimeTokenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/realtime/token', [RealtimeTokenController::class, 'issueSocketToken'])
        ->middleware('throttle:realtime-token');
    Route::post('/realtime/conversations/{conversation}/join-token', [RealtimeTokenController::class, 'issueConversationJoinToken'])
        ->middleware('throttle:realtime-join');
    Route::post('/realtime/chat-conversations/{chatConversation}/join-token', [RealtimeTokenController::class, 'issueChatConversationJoinToken'])
        ->middleware('throttle:realtime-join');
    Route::post('/realtime/conversations/{conversation}/presence', [RealtimeTokenController::class, 'touchConversationPresence'])
        ->middleware('throttle:realtime-join');
    Route::delete('/realtime/conversations/{conversation}/presence', [RealtimeTokenController::class, 'clearConversationPresence'])
        ->middleware('throttle:realtime-join');
});

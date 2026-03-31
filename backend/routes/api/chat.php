<?php

use App\Http\Controllers\AreaController;
use App\Http\Controllers\Chat\AttachmentController as ChatAttachmentController;
use App\Http\Controllers\Chat\ConversationController as ChatConversationController;
use App\Http\Controllers\Company\ConversationController;
use App\Http\Controllers\ConversationTransferController;
use App\Http\Controllers\SimulatedMessageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/chat/users', [ChatConversationController::class, 'users'])
        ->middleware('throttle:inbox-read');
    Route::get('/chat/conversations', [ChatConversationController::class, 'index'])
        ->middleware('throttle:inbox-read');
    Route::post('/chat/conversations', [ChatConversationController::class, 'store'])
        ->middleware('throttle:bot-write');
    Route::delete('/chat/conversations/{conversation}', [ChatConversationController::class, 'destroy'])
        ->middleware('throttle:bot-write');
    Route::get('/chat/conversations/{conversation}', [ChatConversationController::class, 'show'])
        ->middleware('throttle:inbox-read');
    Route::patch('/chat/conversations/{conversation}/group-name', [ChatConversationController::class, 'updateGroupName'])
        ->middleware('throttle:bot-write');
    Route::post('/chat/conversations/{conversation}/participants', [ChatConversationController::class, 'addParticipant'])
        ->middleware('throttle:bot-write');
    Route::delete('/chat/conversations/{conversation}/participants/{participant}', [ChatConversationController::class, 'removeParticipant'])
        ->middleware('throttle:bot-write');
    Route::patch('/chat/conversations/{conversation}/participants/{participant}/admin', [ChatConversationController::class, 'updateParticipantAdmin'])
        ->middleware('throttle:bot-write');
    Route::post('/chat/conversations/{conversation}/leave', [ChatConversationController::class, 'leaveGroup'])
        ->middleware('throttle:bot-write');
    Route::delete('/chat/conversations/{conversation}/group', [ChatConversationController::class, 'deleteGroup'])
        ->middleware('throttle:bot-write');
    Route::post('/chat/conversations/{conversation}/messages', [ChatConversationController::class, 'sendMessage'])
        ->middleware('throttle:bot-write');
    Route::patch('/chat/conversations/{conversation}/messages/{message}', [ChatConversationController::class, 'updateMessage'])
        ->middleware('throttle:bot-write');
    Route::delete('/chat/conversations/{conversation}/messages/{message}', [ChatConversationController::class, 'deleteMessage'])
        ->middleware('throttle:bot-write');
    Route::post('/chat/conversations/{conversation}/read', [ChatConversationController::class, 'markRead'])
        ->middleware('throttle:bot-write');
    Route::post('/chat/conversations/{conversation}/messages/{message}/reactions', [ChatConversationController::class, 'toggleReaction'])
        ->middleware('throttle:bot-write');
    Route::get('/chat/attachments/{attachment}/media', [ChatAttachmentController::class, 'media'])
        ->middleware('throttle:inbox-read');

    Route::get('/areas', [AreaController::class, 'index'])->middleware('throttle:inbox-read');
    Route::get('/areas/{area}/users', [AreaController::class, 'users'])->middleware('throttle:inbox-read');
    Route::post('/conversations/{conversation}/transfer', [ConversationTransferController::class, 'store'])
        ->middleware('throttle:bot-write');
    Route::get('/conversations/{conversation}/transfers', [ConversationTransferController::class, 'index'])
        ->middleware('throttle:inbox-read');

    Route::post('/simular/mensagem', [SimulatedMessageController::class, 'store'])
        ->middleware('throttle:simulation');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get(
        '/conversations/{conversation}/messages/{message}/download',
        [ConversationController::class, 'downloadMessageMedia']
    )
        ->name('conversation.message.download');
});

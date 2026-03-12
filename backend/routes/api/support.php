<?php

use App\Http\Controllers\SupportTicketAttachmentController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\SupportTicketMessageAttachmentController;
use App\Http\Controllers\SupportTicketMessageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/suporte/solicitacoes', [SupportTicketController::class, 'store'])
        ->middleware('throttle:bot-write');
    Route::get('/suporte/minhas-solicitacoes', [SupportTicketController::class, 'mine'])
        ->middleware('throttle:inbox-read');
    Route::get('/suporte/minhas-solicitacoes/{ticket}', [SupportTicketController::class, 'showMine'])
        ->middleware('throttle:inbox-read');
    Route::get('/suporte/minhas-solicitacoes/{ticket}/chat', [SupportTicketMessageController::class, 'listMine'])
        ->middleware('throttle:inbox-read');
    Route::post('/suporte/minhas-solicitacoes/{ticket}/chat', [SupportTicketMessageController::class, 'storeMine'])
        ->middleware('throttle:bot-write');

    Route::get('/support/attachments/{attachment}/media', [SupportTicketAttachmentController::class, 'media'])
        ->middleware('throttle:inbox-read');
    Route::get('/support/ticket-chat/attachments/{attachment}/media', [SupportTicketMessageAttachmentController::class, 'media'])
        ->middleware('throttle:inbox-read');
});

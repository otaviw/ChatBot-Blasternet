<?php

use App\Http\Controllers\SupportTicketAttachmentController;
use App\Http\Controllers\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/suporte/solicitacoes', [SupportTicketController::class, 'store'])
        ->middleware('throttle:bot-write');
    Route::get('/suporte/minhas-solicitacoes', [SupportTicketController::class, 'mine'])
        ->middleware('throttle:inbox-read');
    Route::get('/suporte/minhas-solicitacoes/{ticket}', [SupportTicketController::class, 'showMine'])
        ->middleware('throttle:inbox-read');

    Route::get('/support/attachments/{attachment}/media', [SupportTicketAttachmentController::class, 'media'])
        ->middleware('throttle:inbox-read');
});

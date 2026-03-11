<?php

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\ConversationController as AdminConversationController;
use App\Http\Controllers\Admin\SupportTicketController as AdminSupportTicketController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('admin')->group(function () {
        Route::get('/empresas', [CompanyController::class, 'index']);
        Route::post('/empresas', [CompanyController::class, 'store'])->middleware('throttle:bot-write');
        Route::get('/empresas/{company}', [CompanyController::class, 'show']);
        Route::put('/empresas/{company}', [CompanyController::class, 'update'])->middleware('throttle:bot-write');
        Route::put('/empresas/{company}/bot', [CompanyController::class, 'updateBotSettings'])
            ->middleware('throttle:bot-write');
        Route::delete('/empresas/{company}', [CompanyController::class, 'destroy'])
            ->middleware('throttle:bot-write');

        Route::get('/users', [AdminUserController::class, 'index'])->middleware('throttle:inbox-read');
        Route::post('/users', [AdminUserController::class, 'store'])->middleware('throttle:bot-write');
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->middleware('throttle:bot-write');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->middleware('throttle:bot-write');

        Route::get('/conversas', [AdminConversationController::class, 'index'])
            ->middleware('throttle:inbox-read');
        Route::get('/conversas/{conversationId}', [AdminConversationController::class, 'show'])
            ->middleware('throttle:inbox-read');
        Route::post('/conversas/{conversationId}/assumir', [AdminConversationController::class, 'assume'])
            ->middleware('throttle:bot-write');
        Route::post('/conversas/{conversationId}/soltar', [AdminConversationController::class, 'release'])
            ->middleware('throttle:bot-write');
        Route::post('/conversas/{conversationId}/responder-manual', [AdminConversationController::class, 'manualReply'])
            ->middleware('throttle:bot-write');
        Route::post('/conversas/{conversationId}/encerrar', [AdminConversationController::class, 'close'])
            ->middleware('throttle:bot-write');

        Route::get('/empresas/{company}/metricas', [CompanyController::class, 'metrics'])
            ->middleware('throttle:inbox-read');

        Route::put('/conversas/{conversationId}/tags', [AdminConversationController::class, 'updateTags'])
            ->middleware('throttle:bot-write');
        Route::put('/conversas/{conversationId}/contato', [AdminConversationController::class, 'updateContact'])
            ->middleware('throttle:bot-write');

        Route::get('/suporte/solicitacoes', [AdminSupportTicketController::class, 'index'])
            ->middleware('throttle:inbox-read');
        Route::get('/suporte/solicitacoes/{ticket}', [AdminSupportTicketController::class, 'show'])
            ->middleware('throttle:inbox-read');
        Route::put('/suporte/solicitacoes/{ticket}/status', [AdminSupportTicketController::class, 'updateStatus'])
            ->middleware('throttle:bot-write');
    });
});

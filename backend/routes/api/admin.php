<?php

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\ConversationController as AdminConversationController;
use App\Http\Controllers\Admin\ResellerController;
use App\Http\Controllers\Admin\SupportTicketController as AdminSupportTicketController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\SupportTicketMessageController;
use App\Http\Controllers\Company\AuditLogController as CompanyAuditLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'admin'])->group(function () {
    Route::prefix('admin')->group(function () {
        Route::get('/resellers', [ResellerController::class, 'index'])->middleware('throttle:inbox-read');
        Route::post('/resellers', [ResellerController::class, 'store'])->middleware('throttle:bot-write');
        Route::put('/resellers/{reseller}', [ResellerController::class, 'update'])->middleware('throttle:bot-write');
        Route::get('/minha-revenda', [ResellerController::class, 'showMine'])->middleware('throttle:inbox-read');
        Route::put('/minha-revenda', [ResellerController::class, 'updateMine'])->middleware('throttle:bot-write');
        Route::get('/empresas', [CompanyController::class, 'index']);
        Route::post('/empresas', [CompanyController::class, 'store'])->middleware('throttle:bot-write');
        Route::get('/empresas/{company}', [CompanyController::class, 'show']);
        Route::put('/empresas/{company}', [CompanyController::class, 'update'])->middleware('throttle:bot-write');
        Route::put('/empresas/{company}/bot', [CompanyController::class, 'updateBotSettings'])
            ->middleware('throttle:bot-write');
        Route::post('/empresas/{company}/validar-whatsapp', [CompanyController::class, 'validateWhatsApp'])
            ->middleware('throttle:bot-write');
        Route::delete('/empresas/{company}', [CompanyController::class, 'destroy'])
            ->middleware('throttle:bot-write');

        Route::get('/users', [AdminUserController::class, 'index'])
            ->middleware(['throttle:inbox-read']);
        Route::post('/users', [AdminUserController::class, 'store'])
            ->middleware(['throttle:bot-write']);
        Route::put('/users/{user}', [AdminUserController::class, 'update'])
            ->middleware(['throttle:bot-write']);
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])
            ->middleware(['throttle:bot-write']);

        Route::get('/conversas', [AdminConversationController::class, 'index'])
            ->middleware(['throttle:inbox-read']);
        Route::get('/conversas/buscar', [AdminConversationController::class, 'search'])
            ->middleware(['throttle:conversation-search']);
        Route::get('/conversas/{conversationId}', [AdminConversationController::class, 'show'])
            ->middleware(['throttle:inbox-read']);
        Route::post('/conversas/{conversationId}/assumir', [AdminConversationController::class, 'assume'])
            ->middleware(['system.admin', 'throttle:bot-write']);
        Route::post('/conversas/{conversationId}/soltar', [AdminConversationController::class, 'release'])
            ->middleware(['system.admin', 'throttle:bot-write']);
        Route::post('/conversas/{conversationId}/responder-manual', [AdminConversationController::class, 'manualReply'])
            ->middleware(['system.admin', 'throttle:bot-write']);
        Route::post('/conversas/{conversationId}/encerrar', [AdminConversationController::class, 'close'])
            ->middleware(['system.admin', 'throttle:bot-write']);

        Route::get('/empresas/{company}/metricas', [CompanyController::class, 'metrics'])
            ->middleware('throttle:inbox-read');

        Route::put('/conversas/{conversationId}/tags', [AdminConversationController::class, 'updateTags'])
            ->middleware(['system.admin', 'throttle:bot-write']);
        Route::put('/conversas/{conversationId}/contato', [AdminConversationController::class, 'updateContact'])
            ->middleware(['system.admin', 'throttle:bot-write']);

        Route::get('/audit-logs', [CompanyAuditLogController::class, 'index'])
            ->middleware(['throttle:inbox-read']);
        Route::get('/audit-logs/{auditLog}', [CompanyAuditLogController::class, 'show'])
            ->middleware(['throttle:inbox-read']);

        Route::get('/suporte/solicitacoes', [AdminSupportTicketController::class, 'index'])
            ->middleware(['system.admin', 'throttle:inbox-read']);
        Route::get('/suporte/solicitacoes/{ticket}', [AdminSupportTicketController::class, 'show'])
            ->middleware(['system.admin', 'throttle:inbox-read']);
        Route::put('/suporte/solicitacoes/{ticket}/status', [AdminSupportTicketController::class, 'updateStatus'])
            ->middleware(['system.admin', 'throttle:bot-write']);
        Route::get('/suporte/solicitacoes/{ticket}/chat', [SupportTicketMessageController::class, 'listAdmin'])
            ->middleware(['system.admin', 'throttle:inbox-read']);
        Route::post('/suporte/solicitacoes/{ticket}/chat', [SupportTicketMessageController::class, 'storeAdmin'])
            ->middleware(['system.admin', 'throttle:bot-write']);
    });
});

<?php

use App\Http\Controllers\Company\BotController;
use App\Http\Controllers\Company\AiCompanyKnowledgeController;
use App\Http\Controllers\Company\AiConversationController;
use App\Http\Controllers\Company\AiAnalyticsController;
use App\Http\Controllers\Company\AiAuditController;
use App\Http\Controllers\Company\AiMetricsController;
use App\Http\Controllers\Company\ConversationController as CompanyConversationController;
use App\Http\Controllers\Company\QuickReplyController;
use App\Http\Controllers\Company\UserController as CompanyUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::prefix('minha-conta')->group(function () {
        Route::get('/bot', [BotController::class, 'index']);
        Route::put('/bot', [BotController::class, 'update'])->middleware('throttle:bot-write');
        Route::get('/templates', [CompanyConversationController::class, 'listTemplates'])
            ->middleware('throttle:inbox-read');
        Route::get('/conversas', [CompanyConversationController::class, 'index'])
            ->middleware('throttle:inbox-read');
        Route::post('/conversas', [CompanyConversationController::class, 'createConversation'])
            ->middleware('throttle:bot-write');
        Route::get('/conversas/{conversationId}', [CompanyConversationController::class, 'show'])
            ->middleware('throttle:inbox-read');
        Route::get('/mensagens/{messageId}/media', [CompanyConversationController::class, 'media'])
            ->middleware('throttle:inbox-read');
        Route::post('/conversas/{conversationId}/assumir', [CompanyConversationController::class, 'assume'])
            ->middleware('throttle:bot-write');
        Route::post('/conversas/{conversationId}/soltar', [CompanyConversationController::class, 'release'])
            ->middleware('throttle:bot-write');
        Route::post('/conversas/{conversationId}/responder-manual', [CompanyConversationController::class, 'manualReply'])
            ->middleware('throttle:bot-write');
        Route::post('/conversas/{conversationId}/ia/sugestao', [CompanyConversationController::class, 'suggestReply'])
            ->middleware('throttle:bot-write');
        Route::post('/conversas/{conversationId}/transferir', [CompanyConversationController::class, 'transfer'])
            ->middleware('throttle:bot-write');
        Route::post('/conversas/{conversationId}/enviar-template', [CompanyConversationController::class, 'sendTemplate'])
            ->middleware('throttle:bot-write');
        Route::post('/conversas/{conversationId}/encerrar', [CompanyConversationController::class, 'close'])
            ->middleware('throttle:bot-write');
        Route::put('/conversas/{conversationId}/tags', [CompanyConversationController::class, 'updateTags'])
            ->middleware('throttle:bot-write');
        Route::put('/conversas/{conversationId}/contato', [CompanyConversationController::class, 'updateContact'])
            ->middleware('throttle:bot-write');

        Route::get('/respostas-rapidas', [QuickReplyController::class, 'index']);
        Route::post('/respostas-rapidas', [QuickReplyController::class, 'store'])->middleware('throttle:bot-write');
        Route::put('/respostas-rapidas/{quickReply}', [QuickReplyController::class, 'update'])->middleware('throttle:bot-write');
        Route::delete('/respostas-rapidas/{quickReply}', [QuickReplyController::class, 'destroy'])->middleware('throttle:bot-write');
        Route::get('/base-conhecimento', [AiCompanyKnowledgeController::class, 'index'])->middleware('throttle:inbox-read');
        Route::post('/base-conhecimento', [AiCompanyKnowledgeController::class, 'store'])->middleware('throttle:bot-write');
        Route::put('/base-conhecimento/{knowledgeItem}', [AiCompanyKnowledgeController::class, 'update'])->middleware('throttle:bot-write');
        Route::delete('/base-conhecimento/{knowledgeItem}', [AiCompanyKnowledgeController::class, 'destroy'])->middleware('throttle:bot-write');

        Route::get('/users', [CompanyUserController::class, 'index'])->middleware('throttle:inbox-read');
        Route::post('/users', [CompanyUserController::class, 'store'])->middleware('throttle:bot-write');
        Route::put('/users/{user}', [CompanyUserController::class, 'update'])->middleware('throttle:bot-write');
        Route::delete('/users/{user}', [CompanyUserController::class, 'destroy'])->middleware('throttle:bot-write');

        Route::get('/ia/conversas', [AiConversationController::class, 'index'])
            ->middleware('throttle:inbox-read');
        Route::get('/ia/analytics', [AiAnalyticsController::class, 'index'])
            ->middleware('throttle:inbox-read');
        Route::get('/ia/metricas', [AiMetricsController::class, 'index'])
            ->middleware('throttle:inbox-read');
        Route::get('/ia/auditoria', [AiAuditController::class, 'index'])
            ->middleware('throttle:inbox-read');
        Route::get('/ia/auditoria/{logId}', [AiAuditController::class, 'show'])
            ->middleware('throttle:inbox-read');
        Route::post('/ia/conversas', [AiConversationController::class, 'store'])
            ->middleware('throttle:bot-write');
        Route::get('/ia/conversas/{conversationId}', [AiConversationController::class, 'show'])
            ->middleware('throttle:inbox-read');
        Route::post('/ia/conversas/{conversationId}/mensagens', [AiConversationController::class, 'sendMessage'])
            ->middleware('throttle:bot-write');
    });
});

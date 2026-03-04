<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\ConversationTransferController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RealtimeTokenController;
use App\Http\Controllers\SimulatedMessageController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\ConversationController as AdminConversationController;
use App\Http\Controllers\Admin\SupportTicketController as AdminSupportTicketController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Company\BotController;
use App\Http\Controllers\Company\ConversationController as CompanyConversationController;
use App\Http\Controllers\Company\QuickReplyController;
use App\Http\Controllers\Company\UserController as CompanyUserController;

Route::get('/webhooks/whatsapp', [WebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WebhookController::class, 'handle']);

Route::middleware('web')->group(function () {
    Route::get('/sanctum/csrf-cookie', function () {
        return response()->json(['ok' => true]);
    });

    Route::get('/entrar', [HomeController::class, 'index']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/realtime/token', [RealtimeTokenController::class, 'issueSocketToken'])
            ->middleware('throttle:realtime-token');
        Route::post('/realtime/conversations/{conversation}/join-token', [RealtimeTokenController::class, 'issueConversationJoinToken'])
            ->middleware('throttle:realtime-join');
        Route::post('/suporte/solicitacoes', [SupportTicketController::class, 'store'])
            ->middleware('throttle:bot-write');
        Route::get('/suporte/minhas-solicitacoes', [SupportTicketController::class, 'mine'])
            ->middleware('throttle:inbox-read');
        Route::get('/suporte/minhas-solicitacoes/{ticket}', [SupportTicketController::class, 'showMine'])
            ->middleware('throttle:inbox-read');
        Route::get('/dashboard', [HomeController::class, 'dashboard']);
        Route::get('/areas', [AreaController::class, 'index'])->middleware('throttle:inbox-read');
        Route::get('/areas/{area}/users', [AreaController::class, 'users'])->middleware('throttle:inbox-read');
        Route::post('/conversations/{conversation}/transfer', [ConversationTransferController::class, 'store'])
            ->middleware('throttle:bot-write');
        Route::get('/conversations/{conversation}/transfers', [ConversationTransferController::class, 'index'])
            ->middleware('throttle:inbox-read');

        Route::prefix('admin')->group(function () {
            Route::get('/empresas', [CompanyController::class, 'index']);
            Route::post('/empresas', [CompanyController::class, 'store'])->middleware('throttle:bot-write');
            Route::get('/empresas/{company}', [CompanyController::class, 'show']);
            Route::put('/empresas/{company}', [CompanyController::class, 'update'])->middleware('throttle:bot-write');
            Route::put('/empresas/{company}/bot', [CompanyController::class, 'updateBotSettings'])
                ->middleware('throttle:bot-write');

            Route::get('/users', [AdminUserController::class, 'index'])->middleware('throttle:inbox-read');
            Route::post('/users', [AdminUserController::class, 'store'])->middleware('throttle:bot-write');
            Route::put('/users/{user}', [AdminUserController::class, 'update'])->middleware('throttle:bot-write');

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

        Route::prefix('minha-conta')->group(function () {
            Route::get('/bot', [BotController::class, 'index']);
            Route::put('/bot', [BotController::class, 'update'])->middleware('throttle:bot-write');
            Route::get('/conversas', [CompanyConversationController::class, 'index'])
                ->middleware('throttle:inbox-read');
            Route::get('/conversas/{conversationId}', [CompanyConversationController::class, 'show'])
                ->middleware('throttle:inbox-read');
            Route::post('/conversas/{conversationId}/assumir', [CompanyConversationController::class, 'assume'])
                ->middleware('throttle:bot-write');
            Route::post('/conversas/{conversationId}/soltar', [CompanyConversationController::class, 'release'])
                ->middleware('throttle:bot-write');
            Route::post('/conversas/{conversationId}/responder-manual', [CompanyConversationController::class, 'manualReply'])
                ->middleware('throttle:bot-write');
            Route::post('/conversas/{conversationId}/transferir', [CompanyConversationController::class, 'transfer'])
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

            Route::get('/users', [CompanyUserController::class, 'index'])->middleware('throttle:inbox-read');
            Route::post('/users', [CompanyUserController::class, 'store'])->middleware('throttle:bot-write');
            Route::put('/users/{user}', [CompanyUserController::class, 'update'])->middleware('throttle:bot-write');
        });

        Route::post('/simular/mensagem', [SimulatedMessageController::class, 'store'])
            ->middleware('throttle:simulation');
    });
});

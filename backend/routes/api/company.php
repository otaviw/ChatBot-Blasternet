<?php

use App\Http\Controllers\Company\BotController;
use App\Http\Controllers\Company\CampaignController;
use App\Http\Controllers\Company\ContactController;
use App\Http\Controllers\Company\AppointmentController;
use App\Http\Controllers\Company\AiCompanyKnowledgeController;
use App\Http\Controllers\Company\AiConversationController;
use App\Http\Controllers\Company\AiAnalyticsController;
use App\Http\Controllers\Company\AiAuditController;
use App\Http\Controllers\Company\AuditLogController;
use App\Http\Controllers\Company\AiMetricsController;
use App\Http\Controllers\Company\AiSandboxController;
use App\Http\Controllers\Company\AiSuggestionFeedbackController;
use App\Http\Controllers\Company\ConversationController as CompanyConversationController;
use App\Http\Controllers\Company\ConversationTagController;
use App\Http\Controllers\Company\ProductMetricsController;
use App\Http\Controllers\Company\QuickReplyController;
use App\Http\Controllers\Company\CompanyProfileController;
use App\Http\Controllers\Company\UserController as CompanyUserController;
use App\Http\Controllers\Company\IxcClientController;
use Illuminate\Support\Facades\Route;
use App\Support\UserPermissions;

Route::middleware(['web', 'auth', 'company.user'])->group(function () {
    Route::post('/contacts/import', [ContactController::class, 'importCsv'])->middleware('throttle:bot-write');
    Route::post('/campaigns/{campaignId}/start', [CampaignController::class, 'start'])->middleware('throttle:bot-write');

    Route::prefix('minha-conta')->group(function () {
        Route::get('/bot', [BotController::class, 'index']);
        Route::put('/bot', [BotController::class, 'update'])->middleware(['throttle:bot-write', 'critical.audit:settings.ai_config_updated']);
        Route::post('/bot/validar-whatsapp', [BotController::class, 'validateWhatsApp'])->middleware('throttle:bot-write');
        Route::get('/empresa', [CompanyProfileController::class, 'show'])->middleware(['throttle:inbox-read', 'permission:' . UserPermissions::IXC_INTEGRATION_MANAGE]);
        Route::put('/empresa', [CompanyProfileController::class, 'update'])->middleware(['throttle:bot-write', 'critical.audit:settings.integrations_config_updated', 'permission:' . UserPermissions::IXC_INTEGRATION_MANAGE]);
        Route::post('/empresa/validar-ixc', [CompanyProfileController::class, 'validateIxc'])->middleware(['throttle:bot-write', 'permission:' . UserPermissions::IXC_INTEGRATION_MANAGE]);
        Route::get('/uso', [BotController::class, 'usageSnapshot'])->middleware('throttle:inbox-read');
        Route::get('/produto/funil', [ProductMetricsController::class, 'funnel'])->middleware('throttle:inbox-read');
        Route::get('/templates', [CompanyConversationController::class, 'listTemplates'])
            ->middleware('throttle:inbox-read');
        Route::get('/conversas', [CompanyConversationController::class, 'index'])
            ->middleware('throttle:inbox-read');
        Route::get('/conversas/contadores', [CompanyConversationController::class, 'counters'])
            ->middleware('throttle:inbox-read');
        Route::get('/conversas/buscar', [CompanyConversationController::class, 'search'])
            ->middleware('throttle:conversation-search');
        Route::get('/conversas/{conversationId}/mensagens/buscar', [CompanyConversationController::class, 'searchMessages'])
            ->middleware('throttle:conversation-search');
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
        Route::post('/conversas/{conversationId}/transferir', [CompanyConversationController::class, 'transfer'])
            ->middleware('throttle:bot-write');
        Route::post('/conversas/{conversationId}/enviar-template', [CompanyConversationController::class, 'sendTemplate'])
            ->middleware('throttle:bot-write');
        Route::post('/conversas/{conversationId}/encerrar', [CompanyConversationController::class, 'close'])
            ->middleware('throttle:bot-write');
        Route::delete('/conversas/{conversationId}', [CompanyConversationController::class, 'destroy'])
            ->middleware('throttle:bot-write');
        Route::get('/tags', [ConversationTagController::class, 'index'])
            ->middleware('throttle:inbox-read');
        Route::post('/tags', [ConversationTagController::class, 'store'])
            ->middleware('throttle:bot-write');
        Route::put('/tags/{tag}', [ConversationTagController::class, 'update'])
            ->middleware('throttle:bot-write');
        Route::delete('/tags/{tag}', [ConversationTagController::class, 'destroy'])
            ->middleware('throttle:bot-write');
        Route::put('/conversas/{conversationId}/tags', [CompanyConversationController::class, 'updateTags'])
            ->middleware('throttle:bot-write');
        Route::post('/conversas/{conversationId}/tags', [ConversationTagController::class, 'attach'])
            ->middleware('throttle:bot-write');
        Route::delete('/conversas/{conversationId}/tags/{tagId}', [ConversationTagController::class, 'detach'])
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
        Route::post('/users', [CompanyUserController::class, 'store'])->middleware(['throttle:bot-write', 'critical.audit:user.created']);
        Route::put('/users/{user}', [CompanyUserController::class, 'update'])->middleware(['throttle:bot-write', 'critical.audit:user.permissions_changed']);
        Route::delete('/users/{user}', [CompanyUserController::class, 'destroy'])->middleware(['throttle:bot-write', 'critical.audit:user.deleted']);

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('throttle:inbox-read');
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->middleware('throttle:inbox-read');

        Route::get('/agendamentos/configuracoes', [AppointmentController::class, 'settings'])
            ->middleware('throttle:inbox-read');
        Route::put('/agendamentos/configuracoes', [AppointmentController::class, 'updateSettings'])
            ->middleware('throttle:bot-write');
        Route::get('/agendamentos/servicos', [AppointmentController::class, 'listServices'])
            ->middleware('throttle:inbox-read');
        Route::post('/agendamentos/servicos', [AppointmentController::class, 'createService'])
            ->middleware('throttle:bot-write');
        Route::put('/agendamentos/servicos/{service}', [AppointmentController::class, 'updateService'])
            ->middleware('throttle:bot-write');
        Route::delete('/agendamentos/servicos/{service}', [AppointmentController::class, 'disableService'])
            ->middleware('throttle:bot-write');
        Route::get('/agendamentos/atendentes', [AppointmentController::class, 'listStaff'])
            ->middleware('throttle:inbox-read');
        Route::put('/agendamentos/atendentes/{staffProfile}', [AppointmentController::class, 'updateStaff'])
            ->middleware('throttle:bot-write');
        Route::put('/agendamentos/atendentes/{staffProfile}/jornada', [AppointmentController::class, 'replaceWorkingHours'])
            ->middleware('throttle:bot-write');
        Route::get('/agendamentos/bloqueios', [AppointmentController::class, 'listTimeOffs'])
            ->middleware('throttle:inbox-read');
        Route::post('/agendamentos/bloqueios', [AppointmentController::class, 'createTimeOff'])
            ->middleware('throttle:bot-write');
        Route::delete('/agendamentos/bloqueios/{timeOff}', [AppointmentController::class, 'deleteTimeOff'])
            ->middleware('throttle:bot-write');
        Route::get('/agendamentos/disponibilidade', [AppointmentController::class, 'availability'])
            ->middleware('throttle:inbox-read');
        Route::get('/agendamentos', [AppointmentController::class, 'listAppointments'])
            ->middleware('throttle:inbox-read');
        Route::post('/agendamentos', [AppointmentController::class, 'createAppointment'])
            ->middleware('throttle:bot-write');
        Route::patch('/agendamentos/{appointment}/status', [AppointmentController::class, 'updateStatus'])
            ->middleware('throttle:bot-write');
        Route::delete('/agendamentos/{appointment}', [AppointmentController::class, 'deleteAppointment'])
            ->middleware('throttle:bot-write');

        Route::get('/contatos', [ContactController::class, 'index'])->middleware('throttle:inbox-read');
        Route::post('/contatos', [ContactController::class, 'store'])->middleware('throttle:bot-write');
        Route::post('/contatos/importar-csv', [ContactController::class, 'importCsv'])->middleware('throttle:bot-write');
        Route::patch('/contatos/{contactId}', [ContactController::class, 'update'])->middleware('throttle:bot-write');
        Route::delete('/contatos/{contactId}', [ContactController::class, 'destroy'])->middleware('throttle:bot-write');

        Route::get('/campanhas', [CampaignController::class, 'index'])->middleware('throttle:inbox-read');
        Route::post('/campanhas', [CampaignController::class, 'store'])->middleware('throttle:bot-write');
        Route::post('/campanhas/validar-contatos', [CampaignController::class, 'validateContacts'])->middleware('throttle:inbox-read');
        Route::get('/campanhas/{campaignId}', [CampaignController::class, 'show'])->middleware('throttle:inbox-read');
        Route::post('/campanhas/{campaignId}/iniciar', [CampaignController::class, 'start'])->middleware('throttle:bot-write');
        Route::delete('/campanhas/{campaignId}', [CampaignController::class, 'destroy'])->middleware('throttle:bot-write');

        Route::prefix('ixc')->group(function () {
            Route::get('/clientes', [IxcClientController::class, 'index'])
                ->middleware(['throttle:ixc-read', 'permission:' . UserPermissions::PAGE_IXC_CLIENTS, 'permission:' . UserPermissions::IXC_CLIENTS_VIEW]);
            Route::get('/clientes/{clientId}', [IxcClientController::class, 'show'])
                ->middleware(['throttle:ixc-read', 'permission:' . UserPermissions::PAGE_IXC_CLIENTS, 'permission:' . UserPermissions::IXC_CLIENTS_VIEW]);
            Route::get('/clientes/{clientId}/boletos', [IxcClientController::class, 'invoices'])
                ->middleware(['throttle:ixc-read', 'permission:' . UserPermissions::PAGE_IXC_CLIENTS, 'permission:' . UserPermissions::IXC_INVOICES_VIEW]);
            Route::get('/clientes/{clientId}/boletos/{invoiceId}', [IxcClientController::class, 'invoiceDetail'])
                ->middleware(['throttle:ixc-read', 'permission:' . UserPermissions::PAGE_IXC_CLIENTS, 'permission:' . UserPermissions::IXC_INVOICES_VIEW]);
            Route::post('/clientes/{clientId}/boletos/{invoiceId}/download', [IxcClientController::class, 'downloadInvoice'])
                ->middleware(['throttle:ixc-write', 'permission:' . UserPermissions::PAGE_IXC_CLIENTS, 'permission:' . UserPermissions::IXC_INVOICES_DOWNLOAD]);
            Route::post('/clientes/{clientId}/boletos/{invoiceId}/enviar-email', [IxcClientController::class, 'sendInvoiceEmail'])
                ->middleware(['throttle:ixc-write', 'permission:' . UserPermissions::PAGE_IXC_CLIENTS, 'permission:' . UserPermissions::IXC_INVOICES_SEND_EMAIL]);
            Route::post('/clientes/{clientId}/boletos/{invoiceId}/enviar-sms', [IxcClientController::class, 'sendInvoiceSms'])
                ->middleware(['throttle:ixc-write', 'permission:' . UserPermissions::PAGE_IXC_CLIENTS, 'permission:' . UserPermissions::IXC_INVOICES_SEND_SMS]);
        });

        Route::post('/ia/sugestoes/{suggestionId}/feedback', [AiSuggestionFeedbackController::class, 'store'])
            ->middleware('throttle:bot-write');

        Route::post('/ia/sandbox', [AiSandboxController::class, 'test'])
            ->middleware('throttle:ai-sandbox');

        Route::post('/conversas/{conversationId}/ia/sugestao', [CompanyConversationController::class, 'suggestReply'])
                ->middleware('throttle:bot-write');
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
        Route::post('/ia/conversas/{conversationId}/mensagens/stream', [AiConversationController::class, 'streamMessage'])
                ->middleware('throttle:bot-write');
    });
});

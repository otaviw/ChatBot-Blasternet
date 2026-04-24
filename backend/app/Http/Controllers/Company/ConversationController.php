<?php

namespace App\Http\Controllers\Company;

use App\Actions\Conversation\AssignConversationAgentAction;
use App\Actions\Conversation\SearchConversationsAction;
use App\Actions\Conversation\SyncConversationTagsAction;
use App\Actions\Conversation\ToggleConversationPrivacyAction;
use App\Actions\Conversation\UpdateConversationStatusAction;
use App\Actions\Company\Conversation\GenerateAiSuggestionForConversationAction;
use App\Actions\Company\Conversation\ListCompanyConversationsAction;
use App\Actions\Company\Conversation\SearchCompanyConversationMessagesAction;
use App\Actions\Company\Conversation\ServeCompanyConversationMediaAction;
use App\Actions\Company\Conversation\ShowCompanyConversationAction;
use App\Actions\Company\Conversation\TransferCompanyConversationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CreateConversationRequest;
use App\Http\Requests\Company\ManualReplyRequest;
use App\Http\Requests\Company\SearchConversationMessagesRequest;
use App\Http\Requests\Company\SearchConversationsRequest;
use App\Http\Requests\Company\SendConversationTemplateRequest;
use App\Http\Requests\Company\TransferConversationRequest;
use App\Http\Requests\Company\UpdateConversationContactRequest;
use App\Http\Requests\Company\UpdateConversationTagsRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AuditLogService;
use App\Services\AuditService;
use App\Services\Company\CompanyConversationSupportService;
use App\Services\Company\CompanyConversationCountersService;
use App\Services\Company\CompanyUsageLimitsService;
use App\Services\MessageDeliveryStatusService;
use App\Services\MessageMediaStorageService;
use App\Services\WhatsApp\WhatsAppSendService;
use Illuminate\Support\Facades\Storage;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use App\Support\MessageDeliveryStatus;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function __construct(
        private WhatsAppSendService $whatsAppSend,
        private MessageDeliveryStatusService $deliveryStatus,
        private MessageMediaStorageService $mediaStorage,
        private AuditLogService $auditLog,
        private CompanyConversationSupportService $conversationSupport,
        private CompanyConversationCountersService $countersService,
        private CompanyUsageLimitsService $usageLimits
    ) {}

    public function index(Request $request, ListCompanyConversationsAction $action): JsonResponse
    {
        $user = $request->user();

        return response()->json($action->handle($user, $request));
    }

    public function search(SearchConversationsRequest $request, SearchConversationsAction $action): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        return response()->json($action->handleForCompanyUser($user, $validated));
    }

    public function counters(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->countersService->buildForCompany((int) $user->company_id));
    }

    public function searchMessages(
        SearchConversationMessagesRequest $request,
        int $conversationId,
        SearchCompanyConversationMessagesAction $action
    ): JsonResponse {
        $user = $request->user();

        $validated = $request->validated();

        $messagesPerPage = (int) ($validated['messages_per_page'] ?? 25);
        $payload = $action->handle($user, $conversationId, (string) $validated['q'], $messagesPerPage);
        if (! $payload) {
            return response()->json([
                'message' => 'Conversa não encontrada para esta empresa.',
            ], 404);
        }

        return response()->json($payload);
    }

    public function show(Request $request, int $conversationId, ShowCompanyConversationAction $action): JsonResponse
    {
        $user = $request->user();

        $payload = $action->handle($user, $conversationId, $request);
        if (! $payload) {
            return response()->json([
                'message' => 'Conversa não encontrada para esta empresa.',
            ], 404);
        }

        return response()->json($payload);
    }

    public function media(Request $request, int $messageId, ServeCompanyConversationMediaAction $action)
    {
        $user = $request->user();

        return $action->handle($user, $messageId);
    }

    public function suggestReply(
        Request $request,
        int $conversationId,
        GenerateAiSuggestionForConversationAction $action
    ): JsonResponse {
        $user = $request->user();

        try {
            $payload = $action->handle($user, $conversationId);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = collect($errors)->flatten()->first();

            return response()->json([
                'message' => $message ?: 'Não foi possível gerar sugestão da IA.',
                'errors' => $errors,
            ], 422);
        }

        if (! $payload) {
            return response()->json([
                'message' => 'Conversa não encontrada para esta empresa.',
            ], 404);
        }

        return response()->json($payload);
    }

    public function assume(Request $request, int $conversationId, AssignConversationAgentAction $action): JsonResponse
    {
        $user = $request->user();

        $conversation = $action->handle($request, $user, $conversationId, 'assume');
        if (! $conversation) {
            return response()->json(['message' => 'Conversa não encontrada para esta empresa.'], 404);
        }

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function release(Request $request, int $conversationId, AssignConversationAgentAction $action): JsonResponse
    {
        $user = $request->user();

        $conversation = $action->handle($request, $user, $conversationId, 'release');
        if (! $conversation) {
            return response()->json(['message' => 'Conversa não encontrada para esta empresa.'], 404);
        }

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function manualReply(ManualReplyRequest $request, int $conversationId): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->with(['company', 'currentArea:id,name'])
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa não encontrada para esta empresa.'], 404);
        }

        if (! $conversation->isManualMode()) {
            $this->conversationSupport->assignConversationToCurrentUser($conversation, $user);
        } elseif ($conversation->assigned_type === ConversationAssignedType::USER && (int) $conversation->assigned_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Conversa assumida por outro operador.',
            ], 409);
        } elseif ($conversation->assigned_type === ConversationAssignedType::AREA && ! $user->hasArea((int) ($conversation->assigned_id ?? 0))) {
            return response()->json([
                'message' => 'Conversa destinada para outra área de atendimento.',
            ], 409);
        } elseif ($conversation->assigned_type === ConversationAssignedType::AREA) {
            $this->conversationSupport->assignConversationToCurrentUser($conversation, $user, (int) ($conversation->assigned_id ?? 0));
        } elseif (in_array($conversation->assigned_type, [ConversationAssignedType::BOT, ConversationAssignedType::UNASSIGNED], true)) {
            $this->conversationSupport->assignConversationToCurrentUser($conversation, $user);
        }

        $conversation->status = ConversationStatus::IN_PROGRESS;
        $conversation->save();

        $trimmedText = trim((string) ($validated['text'] ?? ''));
        $uploadedFile = $request->file('file')  ?? $request->file('image');
        if ($trimmedText === '' && ! $uploadedFile) {
            return response()->json([
                'message' => 'Informe texto ou arquivo para enviar.',
            ], 422);
        }

        $limitCheck = $this->usageLimits->checkAndConsume((int) $conversation->company_id, 'conversation');
        if (! $limitCheck['allowed']) {
            return response()->json([
                'message'       => $limitCheck['error_message'],
                'limit_blocked' => true,
                'used'          => $limitCheck['used'],
                'limit'         => $limitCheck['limit'],
            ], 429);
        }

        $storedMedia = null;
        if ($uploadedFile) {
            $storedMedia = $this->mediaStorage->storeUploadedImage($uploadedFile, $conversation->company_id);
        }

        $mimeType = $uploadedFile?->getMimeType() ?? '';
        $contentType = 'text';
        if ($storedMedia) {
            $contentType = match (true) {
                str_contains($mimeType, 'image/') => 'image',
                str_contains($mimeType, 'video/') => 'video',
                str_contains($mimeType, 'audio/') => 'audio',
                default => 'document'  // PDF/DOC/etc.
            };
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'human',
            'content_type' => $contentType,
            'text' => $trimmedText !== '' ? $trimmedText : null,
            'media_provider' => $storedMedia['provider'] ?? null,
            'media_key' => $storedMedia['key'] ?? null,
            'media_url' => $storedMedia['url'] ?? null,
            'media_mime_type' => $storedMedia['mime_type'] ?? null,
            'media_filename' => $uploadedFile?->getClientOriginalName(),
            'media_size_bytes' => $storedMedia['size_bytes'] ?? null,
            'media_width' => $storedMedia['width'] ?? null,
            'media_height' => $storedMedia['height'] ?? null,
            'delivery_status' => MessageDeliveryStatus::PENDING,
            'meta' => [
                'source' => 'manual',
                'actor_user_id' => $user->id,
                'actor_user_name' => $user->name,
            ],
        ]);

        AuditService::log(
            action: 'send_message',
            entityType: 'message',
            entityId: $message->id,
            newData: $this->buildMessageAuditSummary($message, $conversation, 'manual')
        );

        $sendOutbound = (bool) ($validated['send_outbound'] ?? true);
        $sendResult = null;
        $wasSent = false;

        try {
            $staffDisplayName = trim((string) ($user->appointmentStaffProfile?->display_name ?? ''));
        } catch (\Throwable) {
            $staffDisplayName = '';
        }
        $senderName = $staffDisplayName !== '' ? $staffDisplayName : trim((string) ($user->name ?? ''));
        $senderNameForWhatsApp = str_replace('*', '', $senderName);
        $textWithSender = $senderNameForWhatsApp !== '' && $trimmedText !== ''
            ? "*{$senderNameForWhatsApp}*:\n{$trimmedText}"
            : $trimmedText;

        if ($sendOutbound) {
            if ($contentType === 'text') {
                // Usa envio inteligente: mensagem normal dentro da janela de 24h,
                // template iniciar_conversa fora da janela ou para números novos.
                $sendResult = $this->whatsAppSend->sendSmartMessage(
                    $conversation->company,
                    $conversation->customer_phone,
                    $textWithSender,
                    $conversation
                );
            } else {
                $disk = $message->media_provider ?: config('whatsapp.media_disk', 'public');
                $filePath = Storage::disk($disk)->path($message->media_key);
                $sendResult = $this->whatsAppSend->sendMediaFile(
                    $conversation->company,
                    $conversation->customer_phone,
                    $filePath,
                    $message->media_mime_type,
                    $contentType,
                    $message->text,
                    $message->media_filename
                );
            }

            $wasSent = (bool) ($sendResult['ok'] ?? false);
            $this->deliveryStatus->applySendResult($message, $sendResult, 'manual_reply');

            if ($wasSent) {
                $conversation->last_business_message_at = now();
                $conversation->save();
            }
        }

        $this->auditLog->record($request, 'company.conversation.manual_reply', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'sent' => $wasSent,
        ]);

        $message->refresh();
        $conversation->load(['assignedUser:id,name,email', 'currentArea:id,name']);
        $this->conversationSupport->normalizeConversationAssignmentRelations($conversation);

        return response()->json([
            'ok'              => true,
            'message'         => $message,
            'was_sent'        => $wasSent,
            'conversation'    => $conversation,
            'usage_warning'   => $limitCheck['warning'] ?? false,
            'usage_message'   => $limitCheck['warning_message'] ?? null,
            'usage_used'      => $limitCheck['used'] ?? null,
            'usage_limit'     => $limitCheck['limit'] ?? null,
        ]);
    }

    public function close(Request $request, int $conversationId, UpdateConversationStatusAction $action): JsonResponse
    {
        $user = $request->user();

        $conversation = $action->handle($request, $user, $conversationId, 'close');
        if (! $conversation) {
            return response()->json(['message' => 'Conversa não encontrada para esta empresa.'], 404);
        }

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function destroy(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();

        $conversation = Conversation::where('id', $conversationId)
            ->where('company_id', $user->company_id)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa não encontrada.'], 404);
        }

        $conversation->delete();

        return response()->json(['ok' => true]);
    }

    public function updateTags(
        UpdateConversationTagsRequest $request,
        int $conversationId,
        SyncConversationTagsAction $action
    ): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        $result = $action->handle($request, $user, $conversationId, $validated);
        if (! $result) {
            return response()->json(['message' => 'Conversa não encontrada para esta empresa.'], 404);
        }

        return response()->json([
            'ok' => true,
            'tags' => $result['tags'],
        ]);
    }

    public function updateContact(UpdateConversationContactRequest $request, int $conversationId): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa não encontrada para esta empresa.'], 404);
        }

        $customerName = trim((string) ($validated['customer_name'] ?? ''));
        $customerName = $customerName !== '' ? $customerName : null;
        $before = $conversation->customer_name;

        $conversation->customer_name = $customerName;
        $conversation->save();

        $this->auditLog->record($request, 'company.conversation.contact_updated', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'before_customer_name' => $before,
            'after_customer_name' => $conversation->customer_name,
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function transfer(TransferConversationRequest $request, int $conversationId, TransferCompanyConversationAction $action): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        try {
            $payload = $action->handle($request, $user, $conversationId, $validated);
        } catch (ValidationException $exception) {
            $messages = $exception->errors();

            return response()->json([
                'message' => collect($messages)->flatten()->first() ?: 'Transferencia invalida.',
                'errors' => $messages,
            ], 422);
        }

        if (! $payload) {
            return response()->json(['message' => 'Conversa não encontrada para esta empresa.'], 404);
        }

        return response()->json($payload);
    }

    /**
     * Lista templates aprovados da Meta para a empresa autenticada.
     */
    public function listTemplates(Request $request): JsonResponse
    {
        $user = $request->user();

        $company = \App\Models\Company::find($user->company_id);
        $result  = $this->whatsAppSend->fetchTemplates($company);

        if (! $result['ok']) {
            return response()->json([
                'templates' => [],
                'error'     => $result['error'],
            ], 200); // 200 com lista vazia: frontend trata graciosamente
        }

        return response()->json([
            'templates' => $result['templates'],
        ]);
    }

    /**
     * Cria uma nova conversa (ou reabre existente) e, opcionalmente, envia template de abertura.
     */
    public function createConversation(
        CreateConversationRequest $request,
        UpdateConversationStatusAction $statusAction
    ): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        $normalizedPhone = PhoneNumberNormalizer::normalizeBrazil((string) $validated['customer_phone']);
        if ($normalizedPhone === '') {
            return response()->json(['message' => 'Telefone inválido.'], 422);
        }

        $customerName = trim((string) ($validated['customer_name'] ?? ''));
        $phoneVariants = PhoneNumberNormalizer::variantsForLookup($normalizedPhone);

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereIn('customer_phone', $phoneVariants !== [] ? $phoneVariants : [$normalizedPhone])
            ->orderByDesc('id')
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'company_id'     => (int) $user->company_id,
                'customer_phone' => $normalizedPhone,
                'status'         => ConversationStatus::OPEN,
                'assigned_type'  => ConversationAssignedType::UNASSIGNED,
                'handling_mode'  => ConversationHandlingMode::BOT,
                'customer_name'  => $customerName ?: null,
            ]);
        }

        if ($customerName !== '' && $conversation->customer_name !== $customerName) {
            $conversation->customer_name = $customerName;
        }

        if ($conversation->customer_phone !== $normalizedPhone) {
            $conversation->customer_phone = $normalizedPhone;
        }

        if ($conversation->status === ConversationStatus::CLOSED) {
            $statusAction->reopen($conversation, true);
        }

        $conversation->save();
        $conversation->load(['company', 'currentArea:id,name', 'assignedUser:id,name,email']);

        $sendTemplate = (bool) ($validated['send_template'] ?? false);
        $message      = null;
        $templateSent = false;

        if ($sendTemplate) {
            $templateName = trim((string) ($validated['template_name'] ?? 'iniciar_conversa'));
            if ($templateName === '') {
                $templateName = 'iniciar_conversa';
            }

            $sendResult = $this->whatsAppSend->sendTemplateMessage(
                $conversation->company,
                $normalizedPhone,
                $templateName
            );

            $templateSent = (bool) ($sendResult['ok'] ?? false);
            $templateText = "[Template: {$templateName}]";

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'out',
                'type'            => 'human',
                'content_type'    => 'text',
                'text'            => $templateText,
                'delivery_status' => $templateSent ? MessageDeliveryStatus::SENT : MessageDeliveryStatus::FAILED,
                'meta'            => [
                    'source'        => 'template',
                    'template_name' => $templateName,
                    'actor_user_id' => $user->id,
                    'actor_user_name' => $user->name,
                    'send_result'   => $sendResult,
                ],
            ]);

            AuditService::log(
                action: 'send_message',
                entityType: 'message',
                entityId: $message->id,
                newData: $this->buildMessageAuditSummary($message, $conversation, 'template')
            );

            if ($templateSent) {
                $conversation->last_business_message_at = now();
                $conversation->save();
            }

            $this->deliveryStatus->applySendResult($message, $sendResult, 'template_manual');
            $message->refresh();
        }

        $this->auditLog->record($request, 'company.conversation.created', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'send_template'   => $sendTemplate,
            'template_sent'   => $templateSent,
        ]);

        return response()->json([
            'ok'           => true,
            'conversation' => $conversation,
            'message'      => $message,
            'template_sent' => $templateSent,
        ]);
    }

    /**
     * Envia template para uma conversa existente (reabre janela de atendimento).
     */
    public function sendTemplate(
        SendConversationTemplateRequest $request,
        int $conversationId,
        UpdateConversationStatusAction $statusAction
    ): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->with(['company'])
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa não encontrada.'], 404);
        }

        $templateName = trim((string) ($validated['template_name'] ?? 'iniciar_conversa'));
        if ($templateName === '') {
            $templateName = 'iniciar_conversa';
        }

        $templateLimitCheck = $this->usageLimits->checkAndConsume((int) $conversation->company_id, 'template');
        if (! $templateLimitCheck['allowed']) {
            return response()->json([
                'message'       => $templateLimitCheck['error_message'],
                'limit_blocked' => true,
                'used'          => $templateLimitCheck['used'],
                'limit'         => $templateLimitCheck['limit'],
            ], 429);
        }

        $sendResult = $this->whatsAppSend->sendTemplateMessage(
            $conversation->company,
            $conversation->customer_phone,
            $templateName
        );

        $templateSent = (bool) ($sendResult['ok'] ?? false);
        $templateText = "[Template: {$templateName}]";

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'out',
            'type'            => 'human',
            'content_type'    => 'text',
            'text'            => $templateText,
            'delivery_status' => $templateSent ? MessageDeliveryStatus::SENT : MessageDeliveryStatus::FAILED,
            'meta'            => [
                'source'        => 'template',
                'template_name' => $templateName,
                'actor_user_id' => $user->id,
                'actor_user_name' => $user->name,
                'send_result'   => $sendResult,
            ],
        ]);

        AuditService::log(
            action: 'send_message',
            entityType: 'message',
            entityId: $message->id,
            newData: $this->buildMessageAuditSummary($message, $conversation, 'template')
        );

        if ($templateSent) {
            $conversation->last_business_message_at = now();

            if ($conversation->status === ConversationStatus::CLOSED) {
                $statusAction->reopen($conversation);
            }

            $conversation->save();
        }

        $this->deliveryStatus->applySendResult($message, $sendResult, 'template_manual');
        $message->refresh();
        $conversation->load(['currentArea:id,name', 'assignedUser:id,name,email']);

        $this->auditLog->record($request, 'company.conversation.send_template', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'template_name'   => $templateName,
            'sent'            => $templateSent,
        ]);

        return response()->json([
            'ok'            => $templateSent,
            'message'       => $message,
            'conversation'  => $conversation,
            'error'         => $templateSent ? null : ($sendResult['error'] ?? 'Falha ao enviar template.'),
            'usage_warning' => $templateLimitCheck['warning'] ?? false,
            'usage_message' => $templateLimitCheck['warning_message'] ?? null,
            'usage_used'    => $templateLimitCheck['used'] ?? null,
            'usage_limit'   => $templateLimitCheck['limit'] ?? null,
        ]);
    }

    /**
     * Download de mídia de mensagem (document/image/video/audio).
     */
    public function downloadMessageMedia(
        Request $request,
        int $conversation,
        int $message,
        ToggleConversationPrivacyAction $action
    )
    {
        $user = $request->user();

        $result = $action->handle($user, $conversation, $message);
        if (isset($result['error'], $result['status'])) {
            return response()->json(['error' => $result['error']], (int) $result['status']);
        }

        return response()->download($result['path'], $result['download_name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMessageAuditSummary(Message $message, Conversation $conversation, string $source): array
    {
        $textPreview = null;
        if (is_string($message->text) && trim($message->text) !== '') {
            $textPreview = mb_substr(trim($message->text), 0, 120);
        }

        return [
            'conversation_id' => $conversation->id,
            'source' => $source,
            'direction' => $message->direction,
            'type' => $message->type,
            'content_type' => $message->content_type,
            'delivery_status' => $message->delivery_status,
            'has_media' => ! empty($message->media_key),
            'text_preview' => $textPreview,
        ];
    }
}

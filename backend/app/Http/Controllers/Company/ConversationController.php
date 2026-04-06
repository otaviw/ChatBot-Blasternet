<?php

namespace App\Http\Controllers\Company;

use App\Actions\Company\Conversation\AssumeCompanyConversationAction;
use App\Actions\Company\Conversation\GenerateAiSuggestionForConversationAction;
use App\Actions\Company\Conversation\ListCompanyConversationsAction;
use App\Actions\Company\Conversation\ServeCompanyConversationMediaAction;
use App\Actions\Company\Conversation\ShowCompanyConversationAction;
use App\Actions\Company\Conversation\TransferCompanyConversationAction;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AuditLogService;
use App\Services\Company\CompanyConversationSupportService;
use App\Services\MessageDeliveryStatusService;
use App\Services\MessageMediaStorageService;
use App\Services\NotificationDispatchService;
use App\Services\WhatsAppSendService;
use Illuminate\Support\Facades\Storage;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use App\Support\MessageDeliveryStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function __construct(
        private WhatsAppSendService $whatsAppSend,
        private MessageDeliveryStatusService $deliveryStatus,
        private MessageMediaStorageService $mediaStorage,
        private AuditLogService $auditLog,
        private CompanyConversationSupportService $conversationSupport,
        private NotificationDispatchService $dispatchService
    ) {}

    public function index(Request $request, ListCompanyConversationsAction $action): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        return response()->json($action->handle($user, $request));
    }

    public function show(Request $request, int $conversationId, ShowCompanyConversationAction $action): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $payload = $action->handle($user, $conversationId, $request);
        if (! $payload) {
            return response()->json([
                'message' => 'Conversa nao encontrada para esta empresa.',
            ], 404);
        }

        return response()->json($payload);
    }

    public function media(Request $request, int $messageId, ServeCompanyConversationMediaAction $action)
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        return $action->handle($user, $messageId);
    }

    public function suggestReply(
        Request $request,
        int $conversationId,
        GenerateAiSuggestionForConversationAction $action
    ): JsonResponse {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        try {
            $payload = $action->handle($user, $conversationId);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = collect($errors)->flatten()->first();

            return response()->json([
                'message' => $message ?: 'Nao foi possivel gerar sugestao da IA.',
                'errors' => $errors,
            ], 422);
        }

        if (! $payload) {
            return response()->json([
                'message' => 'Conversa nao encontrada para esta empresa.',
            ], 404);
        }

        return response()->json($payload);
    }

    public function assume(Request $request, int $conversationId, AssumeCompanyConversationAction $action): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $conversation = $action->handle($request, $user, $conversationId);
        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function release(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        $conversation->handling_mode = ConversationHandlingMode::BOT;
        $conversation->assigned_type = ConversationAssignedType::BOT;
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $conversation->status = ConversationStatus::OPEN;
        $conversation->save();

        $this->auditLog->record($request, 'company.conversation.released', $conversation->company_id, [
            'conversation_id' => $conversation->id,
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function manualReply(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'max:' . config('whatsapp.media_max_size_kb', 5120)],
            'send_outbound' => ['sometimes', 'boolean'],
        ]);

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->with(['company', 'currentArea:id,name'])
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
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
            ],
        ]);

        $sendOutbound = (bool) ($validated['send_outbound'] ?? true);
        $sendResult = null;
        $wasSent = false;

        if ($sendOutbound) {
            if ($contentType === 'text') {
                // Usa envio inteligente: mensagem normal dentro da janela de 24h,
                // template iniciar_conversa fora da janela ou para números novos.
                $sendResult = $this->whatsAppSend->sendSmartMessage(
                    $conversation->company,
                    $conversation->customer_phone,
                    $trimmedText,
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
            'ok' => true,
            'message' => $message,
            'was_sent' => $wasSent,
            'conversation' => $conversation,
        ]);
    }

    public function close(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        $prevAssignedType = (string) $conversation->assigned_type;
        $prevAssignedId = $conversation->assigned_id ? (int) $conversation->assigned_id : null;

        $conversation->status = ConversationStatus::CLOSED;
        $conversation->handling_mode = ConversationHandlingMode::BOT;
        $conversation->assigned_type = ConversationAssignedType::UNASSIGNED;
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $conversation->closed_at = now();
        $conversation->save();

        $this->dispatchService->dispatchConversationClosedNotification(
            $conversation,
            $prevAssignedType,
            $prevAssignedId,
            (int) $user->id
        );

        $this->auditLog->record($request, 'company.conversation.closed', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'closed_by' => $user->id,
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function updateTags(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'tags' => ['present', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        $tags = collect($validated['tags'])
            ->map(fn($tag) => strtolower(trim((string) $tag)))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $conversation->tags = $tags;
        $conversation->save();

        $this->auditLog->record($request, 'company.conversation.tags_updated', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'tags' => $tags,
        ]);

        return response()->json([
            'ok' => true,
            'tags' => $tags,
        ]);
    }

    public function updateContact(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:160'],
        ]);

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
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

    public function transfer(Request $request, int $conversationId, TransferCompanyConversationAction $action): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:user,area'],
            'id' => ['nullable', 'integer', 'min:1'],
            'to_user_id' => ['nullable', 'integer', 'min:1'],
            'to_area' => ['nullable', 'string', 'max:120'],
            'send_outbound' => ['sometimes', 'boolean'],
        ]);

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
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        return response()->json($payload);
    }

    /**
     * Lista templates aprovados da Meta para a empresa autenticada.
     */
    public function listTemplates(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

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
    public function createConversation(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $validated = $request->validate([
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_name'  => ['nullable', 'string', 'max:160'],
            'send_template'  => ['sometimes', 'boolean'],
            'template_name'  => ['sometimes', 'string', 'max:100'],
        ]);

        $normalizedPhone = preg_replace('/\D/', '', (string) $validated['customer_phone']);
        if ($normalizedPhone === '') {
            return response()->json(['message' => 'Telefone inválido.'], 422);
        }

        $customerName = trim((string) ($validated['customer_name'] ?? ''));

        $conversation = Conversation::firstOrCreate(
            [
                'company_id'     => (int) $user->company_id,
                'customer_phone' => $normalizedPhone,
            ],
            [
                'status'         => ConversationStatus::OPEN,
                'assigned_type'  => ConversationAssignedType::UNASSIGNED,
                'handling_mode'  => ConversationHandlingMode::BOT,
                'customer_name'  => $customerName ?: null,
            ]
        );

        if ($customerName !== '' && $conversation->customer_name !== $customerName) {
            $conversation->customer_name = $customerName;
        }

        if ($conversation->status === ConversationStatus::CLOSED) {
            $conversation->status        = ConversationStatus::OPEN;
            $conversation->closed_at     = null;
            $conversation->handling_mode = ConversationHandlingMode::BOT;
            $conversation->assigned_type = ConversationAssignedType::UNASSIGNED;
            $conversation->assigned_id   = null;
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
                    'send_result'   => $sendResult,
                ],
            ]);

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
    public function sendTemplate(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $validated = $request->validate([
            'template_name' => ['sometimes', 'string', 'max:100'],
        ]);

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
                'send_result'   => $sendResult,
            ],
        ]);

        if ($templateSent) {
            $conversation->last_business_message_at = now();

            if ($conversation->status === ConversationStatus::CLOSED) {
                $conversation->status    = ConversationStatus::OPEN;
                $conversation->closed_at = null;
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
            'ok'           => $templateSent,
            'message'      => $message,
            'conversation' => $conversation,
            'error'        => $templateSent ? null : ($sendResult['error'] ?? 'Falha ao enviar template.'),
        ]);
    }

    /**
     * Download de mídia de mensagem (document/image/video/audio).
     */
    public function downloadMessageMedia(Request $request, int $conversation, int $message)
    {
        $user = $request->user();
        if (!$user || !$user->isCompanyUser()) {
            return response()->json(['error' => 'Não autorizado'], 403);
        }

        $conv = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->findOrFail($conversation);

        $msg = Message::query()
            ->where('conversation_id', $conversation)
            ->findOrFail($message);

        if (!$msg->media_key) {
            return response()->json(['error' => 'Sem arquivo'], 404);
        }

        $filePath = storage_path("app/public/{$msg->media_key}");
        if (!file_exists($filePath)) {
            return response()->json(['error' => 'Arquivo não encontrado'], 404);
        }

        return response()->download($filePath, $msg->media_filename ?? 'arquivo');
    }
}

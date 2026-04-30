<?php

declare(strict_types=1);


namespace App\Actions\Company\Conversation;

use App\Data\ActionResponse;
use App\Http\Requests\Company\ManualReplyRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AuditService;
use App\Services\Company\CompanyConversationSupportService;
use App\Services\Company\CompanyUsageLimitsService;
use App\Services\MessageDeliveryStatusService;
use App\Services\MessageMediaStorageService;
use App\Services\ProductMetricsService;
use App\Services\WhatsApp\WhatsAppSendService;
use App\Support\ConversationAssignedType;
use App\Support\ProductFunnels;
use App\Support\ConversationStatus;
use App\Support\MessageDeliveryStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ManualReplyAction
{
    public function __construct(
        private readonly WhatsAppSendService $whatsAppSend,
        private readonly MessageDeliveryStatusService $deliveryStatus,
        private readonly MessageMediaStorageService $mediaStorage,
        private readonly AuditLogService $auditLog,
        private readonly CompanyConversationSupportService $conversationSupport,
        private readonly CompanyUsageLimitsService $usageLimits,
        private readonly ProductMetricsService $productMetrics,
    ) {}

    public function handle(ManualReplyRequest $request, User $user, int $conversationId): ActionResponse
    {
        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->with(['company', 'currentArea:id,name'])
            ->first();

        if (! $conversation) {
            return ActionResponse::notFound('Conversa não encontrada para esta empresa.');
        }

        $assignmentError = $this->resolveConversationAssignment($conversation, $user);
        if ($assignmentError !== null) {
            return ActionResponse::conflict($assignmentError);
        }

        $validated    = $request->validated();
        $trimmedText  = trim((string) ($validated['text'] ?? ''));
        $uploadedFile = $request->file('file') ?? $request->file('image');

        if ($trimmedText === '' && ! $uploadedFile) {
            return ActionResponse::unprocessable('Informe texto ou arquivo para enviar.');
        }

        $limitCheck = $this->usageLimits->checkAndConsume((int) $conversation->company_id, 'conversation');
        if (! $limitCheck->allowed) {
            return $limitCheck->toBlockedResponse();
        }

        $storedMedia = null;
        if ($uploadedFile) {
            $storedMedia = $this->mediaStorage->storeUploadedImage($uploadedFile, $conversation->company_id);
        }

        $contentType = $this->resolveContentType($storedMedia, $uploadedFile?->getMimeType() ?? '');

        $message = DB::transaction(function () use ($conversation, $contentType, $trimmedText, $storedMedia, $uploadedFile, $user): Message {
            $conversation->status = ConversationStatus::IN_PROGRESS;
            $conversation->save();

            return Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'out',
                'type'            => 'human',
                'content_type'    => $contentType,
                'text'            => $trimmedText !== '' ? $trimmedText : null,
                'media_provider'  => $storedMedia['provider'] ?? null,
                'media_key'       => $storedMedia['key'] ?? null,
                'media_url'       => $storedMedia['url'] ?? null,
                'media_mime_type' => $storedMedia['mime_type'] ?? null,
                'media_filename'  => $uploadedFile?->getClientOriginalName(),
                'media_size_bytes' => $storedMedia['size_bytes'] ?? null,
                'media_width'     => $storedMedia['width'] ?? null,
                'media_height'    => $storedMedia['height'] ?? null,
                'delivery_status' => MessageDeliveryStatus::PENDING,
                'meta'            => [
                    'source'          => 'manual',
                    'actor_user_id'   => $user->id,
                    'actor_user_name' => $user->name,
                ],
            ]);
        });

        AuditService::log(
            action: 'send_message',
            entityType: 'message',
            entityId: $message->id,
            newData: $this->buildMessageAuditData($message, $conversation, 'manual')
        );

        $sendOutbound = (bool) ($validated['send_outbound'] ?? true);
        $wasSent      = false;

        if ($sendOutbound) {
            [, $wasSent] = $this->sendViaWhatsApp($conversation, $message, $contentType, $trimmedText, $user);
        }

        if ($wasSent) {
            $conversation->last_business_message_at = now();
            $conversation->save();
        }

        $this->auditLog->record($request, 'company.conversation.manual_reply', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'message_id'      => $message->id,
            'sent'            => $wasSent,
        ]);

        $this->productMetrics->track(
            ProductFunnels::FEATURE_PRINCIPAL,
            'manual_or_template_sent',
            'manual_reply_sent',
            (int) $conversation->company_id,
            (int) $user->id,
            [
                'conversation_id' => (int) $conversation->id,
                'message_id' => (int) $message->id,
                'content_type' => (string) $message->content_type,
                'was_sent' => $wasSent,
                'send_outbound' => $sendOutbound,
            ],
        );

        $message->refresh();
        $conversation->load(['assignedUser:id,name,email', 'currentArea:id,name']);
        $this->conversationSupport->normalizeConversationAssignmentRelations($conversation);

        return ActionResponse::ok(array_merge(
            [
                'ok'           => true,
                'message'      => $message,
                'was_sent'     => $wasSent,
                'conversation' => $conversation,
            ],
            $limitCheck->warningPayload()
        ));
    }

    /**
     * Atribui a conversa ao operador conforme o estado atual de atribuição.
     * Retorna mensagem de erro quando o operador não pode assumir, null quando ok.
     */
    private function resolveConversationAssignment(Conversation $conversation, User $user): ?string
    {
        if (! $conversation->isManualMode()) {
            $this->conversationSupport->assignConversationToCurrentUser($conversation, $user);

            return null;
        }

        if ($conversation->assigned_type === ConversationAssignedType::USER && (int) $conversation->assigned_id !== (int) $user->id) {
            return 'Conversa assumida por outro operador.';
        }

        if ($conversation->assigned_type === ConversationAssignedType::AREA && ! $user->hasArea((int) ($conversation->assigned_id ?? 0))) {
            return 'Conversa destinada para outra área de atendimento.';
        }

        if ($conversation->assigned_type === ConversationAssignedType::AREA) {
            $this->conversationSupport->assignConversationToCurrentUser($conversation, $user, (int) ($conversation->assigned_id ?? 0));

            return null;
        }

        if (in_array($conversation->assigned_type, [ConversationAssignedType::BOT, ConversationAssignedType::UNASSIGNED], true)) {
            $this->conversationSupport->assignConversationToCurrentUser($conversation, $user);
        }

        return null;
    }

    private function resolveContentType(?array $storedMedia, string $mimeType): string
    {
        if (! $storedMedia) {
            return 'text';
        }

        return match (true) {
            str_contains($mimeType, 'image/') => 'image',
            str_contains($mimeType, 'video/') => 'video',
            str_contains($mimeType, 'audio/') => 'audio',
            default                            => 'document',
        };
    }

    /** @return array{0: array<string, mixed>|null, 1: bool} */
    private function sendViaWhatsApp(
        Conversation $conversation,
        Message $message,
        string $contentType,
        string $trimmedText,
        User $user
    ): array {
        try {
            $staffDisplayName = trim((string) ($user->appointmentStaffProfile?->display_name ?? ''));
        } catch (\Throwable) {
            $staffDisplayName = '';
        }

        $senderName           = $staffDisplayName !== '' ? $staffDisplayName : trim((string) ($user->name ?? ''));
        $senderNameForWhatsApp = str_replace('*', '', $senderName);
        $textWithSender        = $senderNameForWhatsApp !== '' && $trimmedText !== ''
            ? "*{$senderNameForWhatsApp}*:\n{$trimmedText}"
            : $trimmedText;

        if ($contentType === 'text') {
            $sendResult = $this->whatsAppSend->sendSmartMessage(
                $conversation->company,
                $conversation->customer_phone,
                $textWithSender,
                $conversation
            );
        } else {
            $disk     = $message->media_provider ?: config('whatsapp.media_disk', 'public');
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

        return [$sendResult, $wasSent];
    }

    /** @return array<string, mixed> */
    private function buildMessageAuditData(Message $message, Conversation $conversation, string $source): array
    {
        $textPreview = null;
        if (is_string($message->text) && trim($message->text) !== '') {
            $textPreview = mb_substr(trim($message->text), 0, 120);
        }

        return [
            'conversation_id' => $conversation->id,
            'source'          => $source,
            'direction'       => $message->direction,
            'type'            => $message->type,
            'content_type'    => $message->content_type,
            'delivery_status' => $message->delivery_status,
            'has_media'       => ! empty($message->media_key),
            'text_preview'    => $textPreview,
        ];
    }
}

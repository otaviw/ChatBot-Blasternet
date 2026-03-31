<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Bot\StatefulBotService;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use App\Support\MessageDeliveryStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;

class InboundMessageService
{
    public function __construct(
        private BotReplyService $botReply,
        private WhatsAppSendService $whatsAppSend,
        private StatefulBotService $statefulBot,
        private MessageMediaStorageService $mediaStorage,
        private MessageDeliveryStatusService $deliveryStatus,
        private ConversationInactivityService $conversationInactivityService
    ) {}

    public function handleIncomingText(
        ?Company $company,
        string $from,
        string $text,
        array $inMeta = [],
        array $outMeta = [],
        bool $sendOutbound = true,
        ?string $contactName = null
    ): array {
        $normalizedFrom = $this->normalizePhone($from);
        $normalizedText = trim($text);
        $normalizedContactName = $this->normalizeContactName($contactName);

        if ($normalizedFrom === '' || $normalizedText === '') {
            throw new InvalidArgumentException('Phone e texto sao obrigatorios para processar mensagem.');
        }

        $conversation = $this->bootstrapConversation($company, $normalizedFrom, $normalizedContactName);

        $isFirstInboundMessage = ! Message::where('conversation_id', $conversation->id)
            ->where('direction', 'in')
            ->exists();

        $inMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => 'text',
            'text' => $normalizedText,
            'whatsapp_message_id' => $this->extractWhatsAppMessageId($inMeta),
            'meta' => $inMeta,
        ]);

        if ($conversation->isManualMode()) {
            Log::info('Auto reply ignorado porque conversa esta em modo manual.', [
                'conversation_id' => $conversation->id,
                'company_id' => $company?->id,
                'customer_phone' => $normalizedFrom,
            ]);

            $conversation->status = ConversationStatus::IN_PROGRESS;
            $conversation->save();

            return [
                'conversation' => $conversation,
                'in_message' => $inMessage,
                'out_message' => null,
                'reply' => null,
                'was_sent' => false,
                'auto_replied' => false,
            ];
        }

        $statefulResult = $this->statefulBot->handle(
            $company,
            $conversation,
            $normalizedText,
            $isFirstInboundMessage
        );

        $statefulHandled = (bool) ($statefulResult['handled'] ?? false);
        $reply = $statefulHandled
            ? trim((string) ($statefulResult['reply_text'] ?? ''))
            : $this->botReply->buildReply($company, $normalizedText, $isFirstInboundMessage);

        if ($reply === '') {
            $statefulHandled = false;
            $reply = $this->botReply->buildReply($company, $normalizedText, $isFirstInboundMessage);
        }

        [$outMessage, $updatedConversation] = DB::transaction(function () use (
            $conversation,
            $reply,
            $outMeta,
            $statefulHandled,
            $statefulResult,
            $normalizedContactName
        ) {
            $lockedConversation = Conversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($normalizedContactName !== null) {
                $lockedConversation->customer_name = $normalizedContactName;
            }

            $outMessage = Message::create([
                'conversation_id' => $lockedConversation->id,
                'direction' => 'out',
                'type' => 'bot',
                'content_type' => 'text',
                'text' => $reply,
                'delivery_status' => MessageDeliveryStatus::PENDING,
                'meta' => $outMeta,
            ]);

            if ($statefulHandled) {
                $this->applyStatefulConversationUpdate($lockedConversation, $statefulResult);
            } else {
                $this->applyLegacyBotConversationUpdate($lockedConversation);
            }

            $lockedConversation->save();

            return [$outMessage, $lockedConversation];
        });

        $sendResult = $sendOutbound
            ? $this->whatsAppSend->sendText($company, $from, $reply)
            : null;
        $wasSent = (bool) ($sendResult['ok'] ?? false);

        if ($sendResult !== null) {
            $this->deliveryStatus->applySendResult($outMessage, $sendResult, 'bot_auto_reply');
            $outMessage->refresh();
        }

        if ($sendOutbound && ! $wasSent) {
            Log::warning('Falha ao enviar resposta automatica para WhatsApp.', [
                'conversation_id' => $updatedConversation->id,
                'company_id' => $company?->id,
                'to' => $normalizedFrom,
                'reply_preview' => mb_substr($reply, 0, 140),
            ]);
        }

        return [
            'conversation' => $updatedConversation,
            'in_message' => $inMessage,
            'out_message' => $outMessage,
            'reply' => $reply,
            'was_sent' => $wasSent,
            'auto_replied' => true,
        ];
    }

    public function handleIncomingMedia(
        ?Company $company,
        string $from,
        string $mediaId,
        ?string $caption = null,
        array $inMeta = [],
        ?string $contactName = null
    ): array {
        $normalizedFrom = $this->normalizePhone($from);
        $normalizedMediaId = trim($mediaId);
        $normalizedContactName = $this->normalizeContactName($contactName);
        $captionValue = trim((string) $caption);

        if ($normalizedFrom === '' || $normalizedMediaId === '') {
            throw new InvalidArgumentException('Phone e mediaId sao obrigatorios para processar imagem.');
        }

        $conversation = $this->bootstrapConversation($company, $normalizedFrom, $normalizedContactName);

        $download = $this->whatsAppSend->downloadInboundImage($company, $normalizedMediaId);
        $storedMedia = null;
        if ($download && ($download['binary'] ?? '') !== '') {
            $storedMedia = $this->mediaStorage->storeBinaryImage(
                (string) $download['binary'],
                isset($download['mime_type']) ? (string) $download['mime_type'] : null,
                $company?->id
            );
        }

        $meta = array_merge($inMeta, [
            'media_id' => $normalizedMediaId,
            'media_downloaded' => $storedMedia !== null,
        ]);

        $mediaType = $inMeta['incoming_type'] ?? 'image'; 

        $inMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => $mediaType,
            'text' => $captionValue !== '' ? $captionValue : null,
            'media_provider' => $storedMedia['provider'] ?? null,
            'media_key' => $storedMedia['key'] ?? null,
            'media_url' => $storedMedia['url'] ?? null,
            'media_mime_type' => $storedMedia['mime_type'] ?? null,
            'media_filename' => isset($inMeta['filename']) ? (string) $inMeta['filename'] : null,
            'media_size_bytes' => $storedMedia['size_bytes'] ?? null,
            'media_width' => $storedMedia['width'] ?? null,
            'media_height' => $storedMedia['height'] ?? null,
            'whatsapp_message_id' => $this->extractWhatsAppMessageId($meta),
            'meta' => $meta,
        ]);

        if ($conversation->isManualMode()) {
            $conversation->status = ConversationStatus::IN_PROGRESS;
            $conversation->save();
        }

        return [
            'conversation' => $conversation,
            'in_message' => $inMessage,
            'out_message' => null,
            'reply' => null,
            'was_sent' => false,
            'auto_replied' => false,
        ];
    }

    public function handleIncomingLocation(
        ?Company $company,
        string $from,
        float $latitude,
        float $longitude,
        string $name = '',
        string $address = '',
        array $inMeta = [],
        ?string $contactName = null
    ): array {
        $normalizedFrom = $this->normalizePhone($from);
        $normalizedContactName = $this->normalizeContactName($contactName);

        if ($normalizedFrom === '') {
            throw new InvalidArgumentException('Phone é obrigatório para processar localização.');
        }

        $conversation = $this->bootstrapConversation($company, $normalizedFrom, $normalizedContactName);

        $inMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => 'location',
            'text' => json_encode(compact('latitude', 'longitude', 'name', 'address')),
            'whatsapp_message_id' => $this->extractWhatsAppMessageId($inMeta),
            'meta' => $inMeta,
        ]);

        if ($conversation->isManualMode()) {
            $conversation->status = ConversationStatus::IN_PROGRESS;
            $conversation->save();
        }

        return [
            'conversation' => $conversation,
            'in_message' => $inMessage,
            'out_message' => null,
            'reply' => null,
            'was_sent' => false,
            'auto_replied' => false,
        ];
    }

    public function handleIncomingUploadedImage(
        ?Company $company,
        string $from,
        UploadedFile $imageFile,
        ?string $caption = null,
        array $inMeta = [],
        ?string $contactName = null
    ): array {
        $normalizedFrom = $this->normalizePhone($from);
        $normalizedContactName = $this->normalizeContactName($contactName);
        $captionValue = trim((string) $caption);

        if ($normalizedFrom === '') {
            throw new InvalidArgumentException('Phone e imagem sao obrigatorios para processar mensagem.');
        }

        $conversation = $this->bootstrapConversation($company, $normalizedFrom, $normalizedContactName);

        $storedMedia = $this->mediaStorage->storeUploadedImage($imageFile, $company?->id);
        $meta = array_merge($inMeta, ['media_uploaded' => true]);

        $inMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => 'image',
            'text' => $captionValue !== '' ? $captionValue : null,
            'media_provider' => $storedMedia['provider'] ?? null,
            'media_key' => $storedMedia['key'] ?? null,
            'media_url' => $storedMedia['url'] ?? null,
            'media_mime_type' => $storedMedia['mime_type'] ?? null,
            'media_size_bytes' => $storedMedia['size_bytes'] ?? null,
            'media_width' => $storedMedia['width'] ?? null,
            'media_height' => $storedMedia['height'] ?? null,
            'whatsapp_message_id' => $this->extractWhatsAppMessageId($meta),
            'meta' => $meta,
        ]);

        if ($conversation->isManualMode()) {
            $conversation->status = ConversationStatus::IN_PROGRESS;
            $conversation->save();
        }

        return [
            'conversation' => $conversation,
            'in_message' => $inMessage,
            'out_message' => null,
            'reply' => null,
            'was_sent' => false,
            'auto_replied' => false,
        ];
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? '';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function extractWhatsAppMessageId(array $meta): ?string
    {
        foreach (['wamid', 'whatsapp_message_id', 'message_id'] as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeContactName(?string $contactName): ?string
    {
        $value = trim((string) $contactName);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, 160);
    }

    private function bootstrapConversation(
        ?Company $company,
        string $normalizedFrom,
        ?string $normalizedContactName
    ): Conversation {
        if ($company?->id) {
            $this->conversationInactivityService->closeInactiveConversations((int) $company->id);
        }

        $conversation = Conversation::firstOrCreate(
            [
                'company_id' => $company?->id,
                'customer_phone' => $normalizedFrom,
            ],
            [
                'status' => ConversationStatus::OPEN,
                'assigned_type' => ConversationAssignedType::UNASSIGNED,
                'handling_mode' => ConversationHandlingMode::BOT,
                'customer_name' => $normalizedContactName,
            ]
        );

        if ($normalizedContactName !== null && $conversation->customer_name !== $normalizedContactName) {
            $conversation->customer_name = $normalizedContactName;
            $conversation->save();
        }

        if ($conversation->status === ConversationStatus::CLOSED) {
            $this->reopenClosedConversation($conversation);
            $conversation->save();
        }

        return $conversation;
    }

    private function reopenClosedConversation(Conversation $conversation): void
    {
        $conversation->status = ConversationStatus::OPEN;
        $conversation->closed_at = null;
        $conversation->handling_mode = ConversationHandlingMode::BOT;
        $conversation->assigned_type = ConversationAssignedType::BOT;
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $this->clearBotState($conversation);
    }

    private function applyLegacyBotConversationUpdate(Conversation $conversation): void
    {
        $conversation->status = ConversationStatus::OPEN;
        $conversation->handling_mode = ConversationHandlingMode::BOT;
        $conversation->assigned_type = ConversationAssignedType::BOT;
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $this->clearBotState($conversation);
    }

    /**
     * @param  array<string, mixed>  $statefulResult
     */
    private function applyStatefulConversationUpdate(Conversation $conversation, array $statefulResult): void
    {
        $shouldHandoff = (bool) ($statefulResult['should_handoff'] ?? false);

        if (! $shouldHandoff) {
            $conversation->status = ConversationStatus::OPEN;
            $conversation->handling_mode = (string) ($statefulResult['set_handling_mode'] ?? ConversationHandlingMode::BOT);
            $conversation->assigned_type = (string) ($statefulResult['set_assigned_type'] ?? ConversationAssignedType::BOT);
            $conversation->assigned_id = $statefulResult['set_assigned_id'] ?? null;
            $conversation->current_area_id = $statefulResult['set_current_area_id'] ?? null;
            $conversation->assigned_user_id = null;
            $conversation->assigned_area = null;
            $conversation->assumed_at = null;
            $this->applyBotStateFromResult($conversation, $statefulResult);

            return;
        }

        $handoffTarget = is_array($statefulResult['handoff_target'] ?? null)
            ? $statefulResult['handoff_target']
            : null;

        $conversation->status = ConversationStatus::IN_PROGRESS;
        $conversation->handling_mode = (string) ($statefulResult['set_handling_mode'] ?? ConversationHandlingMode::HUMAN);
        $conversation->assigned_type = (string) ($statefulResult['set_assigned_type'] ?? ConversationAssignedType::UNASSIGNED);
        $conversation->assigned_id = $statefulResult['set_assigned_id'] ?? null;
        $conversation->current_area_id = $statefulResult['set_current_area_id'] ?? null;
        $conversation->assigned_user_id = null;
        $targetAreaName = is_array($handoffTarget)
            ? trim((string) ($handoffTarget['name'] ?? ''))
            : '';
        $conversation->assigned_area = $targetAreaName === '' ? null : $targetAreaName;
        $conversation->assumed_at = null;
        $this->clearBotState($conversation);
    }

    /**
     * @param  array<string, mixed>  $statefulResult
     */
    private function applyBotStateFromResult(Conversation $conversation, array $statefulResult): void
    {
        if ((bool) ($statefulResult['clear_state'] ?? false)) {
            $this->clearBotState($conversation);

            return;
        }

        $newState = is_array($statefulResult['new_state'] ?? null)
            ? $statefulResult['new_state']
            : null;

        if (! $newState) {
            $this->clearBotState($conversation);

            return;
        }

        $conversation->bot_flow = $newState['flow'] ?? null;
        $conversation->bot_step = $newState['step'] ?? null;
        $conversation->bot_context = is_array($newState['context'] ?? null)
            ? $newState['context']
            : [];
        $conversation->bot_last_interaction_at = now();
    }

    private function clearBotState(Conversation $conversation): void
    {
        $conversation->bot_flow = null;
        $conversation->bot_step = null;
        $conversation->bot_context = null;
        $conversation->bot_last_interaction_at = null;
    }
}


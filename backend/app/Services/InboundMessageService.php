<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiMetricsService;
use App\Services\Ai\AiSafetyPipelineService;
use App\Services\Ai\ChatbotAiDecisionService;
use App\Services\Ai\ConversationAiSuggestionService;
use App\Services\Bot\StatefulBotService;
use App\Services\WhatsApp\WhatsAppSendService;
use App\Support\MessageDeliveryStatus;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboundMessageService
{
    public function __construct(
        private BotReplyService $botReply,
        private WhatsAppSendService $whatsAppSend,
        private StatefulBotService $statefulBot,
        private MessageMediaStorageService $mediaStorage,
        private MessageDeliveryStatusService $deliveryStatus,
        private ConversationBootstrapService $conversationBootstrap,
        private ConversationStateService $conversationState,
        private ChatbotAiDecisionService $chatbotAiDecision,
        private ConversationAiSuggestionService $chatbotAiSuggestion,
        private AiMetricsService $aiMetrics,
        private AiSafetyPipelineService $safetyPipeline
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
            throw new InvalidArgumentException('Phone e texto sao obrigatórios para processar mensagem.');
        }

        // Deduplicação — camada 2 (service):
        // Segunda linha de defesa contra wamids já processados. Posicionada antes do
        // bootstrap para não alterar estado (last_user_message_at, etc.) em mensagens
        // repetidas. O job já faz o check da camada 1, mas dois jobs concorrentes podem
        // passar pela camada 1 ao mesmo tempo antes de qualquer um persistir a mensagem.
        $wamid = $this->extractWhatsAppMessageId($inMeta);
        if ($wamid !== null) {
            $existing = Message::where('whatsapp_message_id', $wamid)->first();
            if ($existing !== null) {
                Log::info('InboundMessageService: mensagem de texto duplicada ignorada.', [
                    'wamid'       => $wamid,
                    'from_hash'   => self::maskPhone($normalizedFrom),
                    'message_id'  => $existing->id,
                    'company_id'  => $company?->id,
                ]);

                return [
                    'conversation'  => $existing->conversation,
                    'in_message'    => $existing,
                    'out_message'   => null,
                    'reply'         => null,
                    'was_sent'      => false,
                    'auto_replied'  => false,
                ];
            }
        }

        $conversation = $this->conversationBootstrap->bootstrap($company, $normalizedFrom, $normalizedContactName);

        $isFirstInboundMessage = ! Message::where('conversation_id', $conversation->id)
            ->where('direction', 'in')
            ->exists();

        $inMessage = $this->createMessageOrFetchDuplicate([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'type' => 'user',
            'content_type' => 'text',
            'text' => $normalizedText,
            'whatsapp_message_id' => $this->extractWhatsAppMessageId($inMeta),
            'meta' => $inMeta,
        ]);

        // Race condition Layer 3: dois jobs passaram pela Layer 2 ao mesmo tempo.
        // A unique constraint garantiu que só uma mensagem foi criada; o segundo job
        // recebeu a já existente. Retorna cedo para não enviar resposta duplicada.
        if (! $inMessage->wasRecentlyCreated) {
            Log::info('InboundMessageService: race condition resolvida no handleIncomingText — resposta do bot suprimida.', [
                'wamid'      => $wamid,
                'message_id' => $inMessage->id,
                'company_id' => $company?->id,
            ]);

            return [
                'conversation'  => $conversation,
                'in_message'    => $inMessage,
                'out_message'   => null,
                'reply'         => null,
                'was_sent'      => false,
                'auto_replied'  => false,
            ];
        }

        if ($conversation->isManualMode()) {
            Log::info('Auto reply ignorado porque conversa esta em modo manual.', [
                'conversation_id' => $conversation->id,
                'company_id' => $company?->id,
                'customer_phone' => $normalizedFrom,
            ]);

            $conversation->status = \App\Support\ConversationStatus::IN_PROGRESS;
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
        $replyMessage = $statefulHandled ? ($statefulResult['reply_message'] ?? null) : null;
        $reply = $statefulHandled
            ? trim((string) ($statefulResult['reply_text'] ?? ''))
            : $this->botReply->buildReply($company, $normalizedText, $isFirstInboundMessage);

        if ($reply === '') {
            $statefulHandled = false;
            $replyMessage = null;
            $reply = $this->botReply->buildReply($company, $normalizedText, $isFirstInboundMessage);
        }

        // Tenta substituir resposta clássica por resposta gerada via IA, quando habilitado
        if ($company !== null && $this->chatbotAiDecision->shouldUseAi($company)) {
            $safetyResult = $this->safetyPipeline->run($normalizedText);
            if ($safetyResult->blocked) {
                Log::info('chatbot.safety_blocked', [
                    'conversation_id' => (int) $conversation->id,
                    'company_id' => (int) ($company->id ?? 0),
                    'stage' => $safetyResult->blockStage,
                    'reason' => $safetyResult->blockReason,
                    'flags' => $safetyResult->flags,
                ]);
            } else {
                $aiReply = $this->generateChatbotAiReply($company, $conversation);
                if ($aiReply !== null) {
                    $reply = $aiReply;
                    $statefulHandled = false;
                    $replyMessage = null;
                }
            }
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
                $this->conversationState->applyStatefulUpdate($lockedConversation, $statefulResult);
            } else {
                $this->conversationState->applyLegacyUpdate($lockedConversation);
            }

            $lockedConversation->save();

            return [$outMessage, $lockedConversation];
        });

        $sendResult = null;
        if ($sendOutbound) {
            if ($statefulHandled && is_array($replyMessage)) {
                $msgType = $replyMessage['type'] ?? 'text';
                if ($msgType === 'interactive_buttons') {
                    $sendResult = $this->whatsAppSend->sendInteractiveButtons(
                        $from,
                        $replyMessage['body_text'] ?? $reply,
                        $replyMessage['buttons'] ?? [],
                        array_filter([
                            'header_text' => $replyMessage['header_text'] ?? '',
                            'footer_text' => $replyMessage['footer_text'] ?? '',
                        ], fn ($v) => $v !== ''),
                        $company
                    );
                } elseif ($msgType === 'interactive_list') {
                    $sendResult = $this->whatsAppSend->sendInteractiveList(
                        $from,
                        $replyMessage['body_text'] ?? $reply,
                        $replyMessage['rows'] ?? [],
                        array_filter([
                            'header_text'  => $replyMessage['header_text'] ?? '',
                            'footer_text'  => $replyMessage['footer_text'] ?? '',
                            'action_label' => $replyMessage['action_label'] ?? '',
                        ], fn ($v) => $v !== ''),
                        $company
                    );
                } else {
                    $sendResult = $this->whatsAppSend->sendText($company, $from, $reply);
                }
            } else {
                $sendResult = $this->whatsAppSend->sendText($company, $from, $reply);
            }
        }
        $wasSent = (bool) ($sendResult['ok'] ?? false);

        if ($sendResult !== null) {
            $this->deliveryStatus->applySendResult($outMessage, $sendResult, 'bot_auto_reply');
            $outMessage->refresh();
        }

        if ($sendOutbound && ! $wasSent) {
            Log::warning('Falha ao enviar resposta automatica para WhatsApp.', [
                'conversation_id' => $updatedConversation->id,
                'company_id'      => $company?->id,
                'to_hash'         => self::maskPhone($normalizedFrom),
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
            throw new InvalidArgumentException('Phone e mediaId sao obrigatórios para processar imagem.');
        }

        // Deduplicação — camada 2 (service):
        // Verificação crítica aqui porque o download de mídia da Meta API é uma
        // chamada HTTP externa cara. Sem este check, um reenvio duplicado baixaria
        // a mesma mídia desnecessariamente antes de tentar criar a mensagem.
        $wamid = $this->extractWhatsAppMessageId($inMeta);
        if ($wamid !== null) {
            $existing = Message::where('whatsapp_message_id', $wamid)->first();
            if ($existing !== null) {
                Log::info('InboundMessageService: mensagem de mídia duplicada ignorada.', [
                    'wamid'          => $wamid,
                    'from_hash'      => self::maskPhone($normalizedFrom),
                    'message_id'     => $existing->id,
                    'company_id'     => $company?->id,
                    'incoming_type'  => $inMeta['incoming_type'] ?? 'media',
                ]);

                return [
                    'conversation'  => $existing->conversation,
                    'in_message'    => $existing,
                    'out_message'   => null,
                    'reply'         => null,
                    'was_sent'      => false,
                    'auto_replied'  => false,
                ];
            }
        }

        $conversation = $this->conversationBootstrap->bootstrap($company, $normalizedFrom, $normalizedContactName);

        $download = $this->whatsAppSend->downloadInboundImage($company, $normalizedMediaId);
        $storedMedia = null;
        if ($download && ($download['binary'] ?? '') !== '') {
            $storedMedia = $this->mediaStorage->storeBinaryImage(
                (string) $download['binary'],
                isset($download['mime_type']) ? (string) $download['mime_type'] : null,
                $company?->id
            );
        }

        if ($storedMedia === null) {
            Log::warning('handleIncomingMedia: download de mídia falhou ou retornou vazio.', [
                'company_id'   => $company?->id,
                'from_hash'    => self::maskPhone($normalizedFrom),
                'media_id'     => $normalizedMediaId,
                'incoming_type' => $inMeta['incoming_type'] ?? 'unknown',
                'download_null' => $download === null,
                'binary_empty' => $download !== null && ($download['binary'] ?? '') === '',
                'mime_type'    => $download['mime_type'] ?? null,
            ]);
        }

        $meta = array_merge($inMeta, [
            'media_id' => $normalizedMediaId,
            'media_downloaded' => $storedMedia !== null,
        ]);

        $mediaType = $inMeta['incoming_type'] ?? 'image';

        $inMessage = DB::transaction(function () use ($conversation, $mediaType, $captionValue, $storedMedia, $meta) {
            $msg = $this->createMessageOrFetchDuplicate([
                'conversation_id' => $conversation->id,
                'direction' => 'in',
                'type' => 'user',
                'content_type' => $mediaType,
                'text' => $captionValue !== '' ? $captionValue : null,
                'media_provider' => $storedMedia['provider'] ?? null,
                'media_key' => $storedMedia['key'] ?? null,
                'media_url' => $storedMedia['url'] ?? null,
                'media_mime_type' => $storedMedia['mime_type'] ?? null,
                'media_filename' => isset($meta['filename']) ? (string) $meta['filename'] : null,
                'media_size_bytes' => $storedMedia['size_bytes'] ?? null,
                'media_width' => $storedMedia['width'] ?? null,
                'media_height' => $storedMedia['height'] ?? null,
                'whatsapp_message_id' => $this->extractWhatsAppMessageId($meta),
                'meta' => $meta,
            ]);

            if ($conversation->isManualMode()) {
                $conversation->status = \App\Support\ConversationStatus::IN_PROGRESS;
                $conversation->save();
            }

            return $msg;
        });

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

        // Deduplicação — camada 2 (service)
        $wamid = $this->extractWhatsAppMessageId($inMeta);
        if ($wamid !== null) {
            $existing = Message::where('whatsapp_message_id', $wamid)->first();
            if ($existing !== null) {
                Log::info('InboundMessageService: mensagem de localização duplicada ignorada.', [
                    'wamid'      => $wamid,
                    'from_hash'  => self::maskPhone($normalizedFrom),
                    'message_id' => $existing->id,
                    'company_id' => $company?->id,
                ]);

                return [
                    'conversation'  => $existing->conversation,
                    'in_message'    => $existing,
                    'out_message'   => null,
                    'reply'         => null,
                    'was_sent'      => false,
                    'auto_replied'  => false,
                ];
            }
        }

        $conversation = $this->conversationBootstrap->bootstrap($company, $normalizedFrom, $normalizedContactName);

        $inMessage = DB::transaction(function () use ($conversation, $latitude, $longitude, $name, $address, $inMeta) {
            $msg = $this->createMessageOrFetchDuplicate([
                'conversation_id' => $conversation->id,
                'direction' => 'in',
                'type' => 'user',
                'content_type' => 'location',
                'text' => json_encode(compact('latitude', 'longitude', 'name', 'address')),
                'whatsapp_message_id' => $this->extractWhatsAppMessageId($inMeta),
                'meta' => $inMeta,
            ]);

            if ($conversation->isManualMode()) {
                $conversation->status = \App\Support\ConversationStatus::IN_PROGRESS;
                $conversation->save();
            }

            return $msg;
        });

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
            throw new InvalidArgumentException('Phone e imagem sao obrigatórios para processar mensagem.');
        }

        $conversation = $this->conversationBootstrap->bootstrap($company, $normalizedFrom, $normalizedContactName);

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
            $conversation->status = \App\Support\ConversationStatus::IN_PROGRESS;
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
        return PhoneNumberNormalizer::normalizeBrazil($phone);
    }

    /**
     * Cria mensagem capturando race condition de unique constraint (SQLSTATE 23xxx).
     *
     * Cenário: dois jobs passam pelas layers 1 e 2 simultaneamente antes de qualquer
     * um persistir. O DB rejeita o segundo insert com duplicate key. Sem este catch,
     * o job falharia e retentaria desnecessariamente — o dado estaria correto mas os
     * logs de erro seriam ruidosos e os retries desperdiçariam recursos.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createMessageOrFetchDuplicate(array $attributes): Message
    {
        try {
            return Message::create($attributes);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_starts_with((string) $e->getCode(), '23')) {
                $wamid = $attributes['whatsapp_message_id'] ?? null;

                if ($wamid !== null) {
                    $existing = Message::where('whatsapp_message_id', $wamid)->first();

                    if ($existing !== null) {
                        Log::warning('InboundMessageService: race condition resolvida — mensagem já persistida por job concorrente.', [
                            'wamid'               => $wamid,
                            'existing_message_id' => $existing->id,
                            'sqlstate'            => $e->getCode(),
                        ]);

                        return $existing;
                    }
                }
            }

            throw $e;
        }
    }

    private static function maskPhone(string $phone): string
    {
        return substr(hash('sha256', $phone), 0, 12);
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

    /**
     * Tenta gerar uma resposta via IA para o chatbot.
     * Retorna null se a configuração da empresa estiver ausente ou se a IA falhar
     * (nesse caso o bot clássico assume como fallback).
     */
    private function generateChatbotAiReply(Company $company, Conversation $conversation): ?string
    {
        $settings = $company->botSetting;
        if (! $settings) {
            return null;
        }

        try {
            $reply = $this->chatbotAiSuggestion->generateSuggestion($conversation, $settings);

            return $reply !== '' ? $reply : null;
        } catch (Throwable $exception) {
            // A métrica de erro já foi registrada dentro de generateSuggestion.
            // Aqui apenas garantimos o fallback silencioso para o bot clássico.
            Log::warning('chatbot.ai_reply_fallback', [
                'conversation_id' => (int) $conversation->id,
                'company_id' => (int) $company->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}

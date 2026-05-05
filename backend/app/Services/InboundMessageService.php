<?php

declare(strict_types=1);


namespace App\Services;

use App\Models\AiChatbotDecisionLog;
use App\Models\AiUsageLog;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiMetricsService;
use App\Services\Ai\AiSafetyPipelineService;
use App\Services\Ai\ChatbotAiDecisionLoggerService;
use App\Services\Ai\ChatbotAiGuardService;
use App\Services\Ai\ChatbotAiIntentClassifier;
use App\Services\Ai\ChatbotAiPolicyService;
use App\Services\Ai\ChatbotAiSuggestionResultNormalizer;
use App\Services\Ai\ChatbotAiDecisionService;
use App\Services\Ai\ConversationAiSuggestionService;
use App\Services\Ai\Safety\AiSafetyResult;
use App\Services\Bot\StatefulBotService;
use App\Services\WhatsApp\WhatsAppSendService;
use App\Support\ConversationStatus;
use App\Support\MessageDeliveryStatus;
use App\Support\PhoneNumberNormalizer;
use App\Support\ProductFunnels;
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
        private ChatbotAiGuardService $chatbotAiGuard,
        private ChatbotAiIntentClassifier $chatbotAiIntentClassifier,
        private ChatbotAiPolicyService $chatbotAiPolicy,
        private ChatbotAiDecisionLoggerService $chatbotAiDecisionLogger,
        private ConversationAiSuggestionService $chatbotAiSuggestion,
        private AiMetricsService $aiMetrics,
        private AiSafetyPipelineService $safetyPipeline,
        private ProductMetricsService $productMetrics
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

        $wamid = $this->extractWhatsAppMessageId($inMeta);
        if ($early = $this->abortIfDuplicate($wamid, $company, $normalizedFrom, 'texto')) {
            return $early;
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

        if (! $inMessage->wasRecentlyCreated) {
            Log::info('InboundMessageService: race condition resolvida no handleIncomingText — resposta do bot suprimida.', [
                'wamid'      => $wamid,
                'message_id' => $inMessage->id,
                'company_id' => $company?->id,
            ]);

            return $this->noReplyResult($conversation, $inMessage);
        }

        $this->productMetrics->track(
            ProductFunnels::CHATBOT,
            'inbound_received',
            'chatbot_inbound_received',
            $company?->id ? (int) $company->id : null,
            null,
            [
                'conversation_id' => (int) $conversation->id,
                'is_first_message' => $isFirstInboundMessage,
                'is_manual_mode' => $conversation->isManualMode(),
            ],
        );

        if ($conversation->isManualMode()) {
            Log::info('Auto reply ignorado porque conversa esta em modo manual.', [
                'conversation_id' => $conversation->id,
                'company_id' => $company?->id,
                'customer_phone' => $normalizedFrom,
            ]);

            $conversation->status = ConversationStatus::IN_PROGRESS;
            $conversation->save();

            return $this->noReplyResult($conversation, $inMessage);
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

        $gateResult = null;
        if ($company !== null) {
            $gateResult = $this->chatbotAiGuard->gateResult($company, $conversation, [
                'channel' => 'whatsapp',
                'entrypoint' => 'inbound_text',
            ]);

            if (! (bool) ($gateResult['allowed'] ?? false)) {
                Log::info('chatbot.ai_guard_blocked', [
                    'conversation_id' => (int) $conversation->id,
                    'company_id' => (int) ($company->id ?? 0),
                    'reasons' => $gateResult['reasons'] ?? [],
                    'gates' => $gateResult['gates'] ?? [],
                ]);
            }
        }

        [$reply, $replyMessage] = $this->applyAiAssistiveDecision(
            $reply,
            $replyMessage,
            $statefulHandled,
            $company,
            $conversation,
            $inMessage,
            $gateResult,
            $normalizedFrom,
            $normalizedText,
            $inMeta
        );

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

        $this->productMetrics->track(
            ProductFunnels::CHATBOT,
            'auto_reply_sent',
            'chatbot_auto_reply_sent',
            $company?->id ? (int) $company->id : null,
            null,
            [
                'conversation_id' => (int) $updatedConversation->id,
                'message_id' => (int) $outMessage->id,
                'was_sent' => $wasSent,
                'stateful_handled' => $statefulHandled,
                'send_outbound' => $sendOutbound,
            ],
        );

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

        if ($early = $this->abortIfDuplicate(
            $this->extractWhatsAppMessageId($inMeta),
            $company,
            $normalizedFrom,
            'mídia',
            ['incoming_type' => $inMeta['incoming_type'] ?? 'media']
        )) {
            return $early;
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
                'conversation_id'     => $conversation->id,
                'direction'           => 'in',
                'type'                => 'user',
                'content_type'        => $mediaType,
                'text'                => $captionValue !== '' ? $captionValue : null,
                'media_provider'      => $storedMedia['provider'] ?? null,
                'media_key'           => $storedMedia['key'] ?? null,
                'media_url'           => $storedMedia['url'] ?? null,
                'media_mime_type'     => $storedMedia['mime_type'] ?? null,
                'media_filename'      => isset($meta['filename']) ? (string) $meta['filename'] : null,
                'media_size_bytes'    => $storedMedia['size_bytes'] ?? null,
                'media_width'         => $storedMedia['width'] ?? null,
                'media_height'        => $storedMedia['height'] ?? null,
                'whatsapp_message_id' => $this->extractWhatsAppMessageId($meta),
                'meta'                => $meta,
            ]);

            $this->updateManualModeStatus($conversation);

            return $msg;
        });

        return $this->noReplyResult($conversation, $inMessage);
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

        if ($early = $this->abortIfDuplicate($this->extractWhatsAppMessageId($inMeta), $company, $normalizedFrom, 'localização')) {
            return $early;
        }

        $conversation = $this->conversationBootstrap->bootstrap($company, $normalizedFrom, $normalizedContactName);

        $inMessage = DB::transaction(function () use ($conversation, $latitude, $longitude, $name, $address, $inMeta) {
            $msg = $this->createMessageOrFetchDuplicate([
                'conversation_id'     => $conversation->id,
                'direction'           => 'in',
                'type'                => 'user',
                'content_type'        => 'location',
                'text'                => json_encode(compact('latitude', 'longitude', 'name', 'address')),
                'whatsapp_message_id' => $this->extractWhatsAppMessageId($inMeta),
                'meta'                => $inMeta,
            ]);

            $this->updateManualModeStatus($conversation);

            return $msg;
        });

        return $this->noReplyResult($conversation, $inMessage);
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

        $this->updateManualModeStatus($conversation);

        return $this->noReplyResult($conversation, $inMessage);
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
     * Verifica se o wamid já foi processado e retorna o resultado de "sem resposta"
     * imediatamente. Retorna null quando não há duplicata e o processamento deve continuar.
     *
     * @param  array<string, mixed>  $extraLogContext
     * @return array<string, mixed>|null
     */
    private function abortIfDuplicate(
        ?string $wamid,
        ?Company $company,
        string $normalizedFrom,
        string $logMessageType,
        array $extraLogContext = []
    ): ?array {
        if ($wamid === null) {
            return null;
        }

        $existing = Message::where('whatsapp_message_id', $wamid)->first();
        if ($existing === null) {
            return null;
        }

        Log::info("InboundMessageService: mensagem de {$logMessageType} duplicada ignorada.", array_merge([
            'wamid'      => $wamid,
            'from_hash'  => self::maskPhone($normalizedFrom),
            'message_id' => $existing->id,
            'company_id' => $company?->id,
        ], $extraLogContext));

        return $this->noReplyResult($existing->conversation, $existing);
    }

    /**
     * Monta o array padrão de retorno para mensagens recebidas sem resposta automática.
     *
     * @return array<string, mixed>
     */
    private function noReplyResult(?Conversation $conversation, Message $inMessage): array
    {
        return [
            'conversation' => $conversation,
            'in_message'   => $inMessage,
            'out_message'  => null,
            'reply'        => null,
            'was_sent'     => false,
            'auto_replied' => false,
        ];
    }

    /**
     * Atualiza o status da conversa para IN_PROGRESS quando em modo manual.
     * Usado pelos handlers passivos (mídia, localização, upload) que não geram resposta automática.
     */
    private function updateManualModeStatus(Conversation $conversation): void
    {
        if ($conversation->isManualMode()) {
            $conversation->status = ConversationStatus::IN_PROGRESS;
            $conversation->save();
        }
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
            $result = $this->chatbotAiSuggestion->generateSuggestion($conversation, $settings);

            return ChatbotAiSuggestionResultNormalizer::toReplyText($result);
        } catch (Throwable $exception) {
            Log::warning('chatbot.ai_reply_fallback', [
                'conversation_id' => (int) $conversation->id,
                'company_id' => (int) $company->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $gateResult
     * @param  array<string, mixed>|string|null  $replyMessage
     * @param  array<string, mixed>  $inMeta
     * @return array{0: string, 1: array<string, mixed>|string|null}
     */
    private function applyAiAssistiveDecision(
        string $legacyReply,
        mixed $replyMessage,
        bool $statefulHandled,
        ?Company $company,
        Conversation $conversation,
        Message $inMessage,
        ?array $gateResult,
        string $normalizedFrom,
        string $normalizedText,
        array $inMeta
    ): array {
        if ($company === null || ! is_array($gateResult)) {
            return [$legacyReply, $replyMessage];
        }

        $settings = $company->botSetting;
        if (! $settings) {
            return [$legacyReply, $replyMessage];
        }

        $allowed = (bool) ($gateResult['allowed'] ?? false);
        if (! $allowed) {
            return [$legacyReply, $replyMessage];
        }

        $shadowEnabled = (bool) ($settings->ai_chatbot_shadow_mode ?? false);
        $sandboxEnabled = (bool) ($settings->ai_chatbot_sandbox_enabled ?? false);
        $sandboxNumberAllowed = $sandboxEnabled && $this->isSandboxTestNumberAllowed($settings->ai_chatbot_test_numbers, $normalizedFrom);
        $mode = $sandboxNumberAllowed
            ? AiChatbotDecisionLog::MODE_SANDBOX
            : ($shadowEnabled ? AiChatbotDecisionLog::MODE_SHADOW : AiChatbotDecisionLog::MODE_OFF);

        if ($mode === AiChatbotDecisionLog::MODE_OFF) {
            return [$legacyReply, $replyMessage];
        }

        $provider = trim((string) ($settings->ai_provider ?? config('ai.provider', '')));
        $model = trim((string) ($settings->ai_model ?? config('ai.model', '')));
        $startedNs = hrtime(true);
        $safeMessageText = $normalizedText;

        try {
            $safety = $this->safetyPipeline->run($normalizedText);
        } catch (Throwable $exception) {
            $latencyMs = max(0, (int) floor((hrtime(true) - $startedNs) / 1_000_000));

            $this->chatbotAiDecisionLogger->logDecision([
                'company_id' => (int) $company->id,
                'conversation_id' => (int) $conversation->id,
                'message_id' => (int) $inMessage->id,
                'user_id' => null,
                'mode' => $mode,
                'gate_result' => $this->mergeReplyComparison(
                    $this->mergeSafetyResult($gateResult, new AiSafetyResult(true, $normalizedText, 'safety_pipeline_exception', 'safety_pipeline', [])),
                    $legacyReply,
                    null,
                    $legacyReply,
                    false,
                    $statefulHandled,
                    $sandboxNumberAllowed,
                ),
                'intent' => 'fallback',
                'confidence' => 0.0,
                'action' => ChatbotAiPolicyService::ACTION_FALLBACK_LEGACY,
                'handoff_reason' => null,
                'used_knowledge' => false,
                'knowledge_refs' => null,
                'latency_ms' => $latencyMs,
                'tokens_used' => null,
                'provider' => $provider !== '' ? $provider : null,
                'model' => $model !== '' ? $model : null,
                'error' => 'safety_pipeline_exception',
            ]);

            return [$legacyReply, $replyMessage];
        }

        if ($safety->blocked) {
            $latencyMs = max(0, (int) floor((hrtime(true) - $startedNs) / 1_000_000));

            $this->chatbotAiDecisionLogger->logDecision([
                'company_id' => (int) $company->id,
                'conversation_id' => (int) $conversation->id,
                'message_id' => (int) $inMessage->id,
                'user_id' => null,
                'mode' => $mode,
                'gate_result' => $this->mergeReplyComparison(
                    $this->mergeSafetyResult($gateResult, $safety),
                    $legacyReply,
                    null,
                    $legacyReply,
                    false,
                    $statefulHandled,
                    $sandboxNumberAllowed,
                ),
                'intent' => 'fallback',
                'confidence' => 0.0,
                'action' => ChatbotAiPolicyService::ACTION_FALLBACK_LEGACY,
                'handoff_reason' => null,
                'used_knowledge' => false,
                'knowledge_refs' => null,
                'latency_ms' => $latencyMs,
                'tokens_used' => null,
                'provider' => $provider !== '' ? $provider : null,
                'model' => $model !== '' ? $model : null,
                'error' => 'safety_blocked',
            ]);

            return [$legacyReply, $replyMessage];
        }

        $safeMessageText = $safety->sanitizedInput;

        try {
            $classification = $this->chatbotAiIntentClassifier->classify(
                $conversation,
                $settings,
                $safeMessageText,
                ['message_meta' => $inMeta]
            );

            $policyDecision = $this->chatbotAiPolicy->decide(
                $conversation,
                $settings,
                $classification,
                [
                    'message_text' => $safeMessageText,
                    'mode' => $mode,
                ]
            );

            $confidence = is_numeric($policyDecision['confidence'] ?? null)
                ? (float) $policyDecision['confidence']
                : 0.0;
            $intent = trim((string) ($policyDecision['intent'] ?? 'fallback'));
            $reason = trim((string) ($policyDecision['reason'] ?? ''));
            $action = trim((string) ($policyDecision['action'] ?? ChatbotAiPolicyService::ACTION_FALLBACK_LEGACY));

            $finalReply = $legacyReply;
            $finalReplyMessage = $replyMessage;
            $suggestionPayload = null;
            $aiReply = null;
            $aiApplied = false;
            $error = null;

            if (
                $mode === AiChatbotDecisionLog::MODE_SANDBOX
                && $action === ChatbotAiPolicyService::ACTION_SUGGEST_REPLY
            ) {
                $suggestionPayload = $this->generateChatbotAiSuggestionPayload($company, $conversation);
                $aiReply = isset($suggestionPayload['reply']) ? trim((string) $suggestionPayload['reply']) : null;

                if ($aiReply !== null && $aiReply !== '') {
                    $finalReply = $aiReply;
                    $finalReplyMessage = $this->applyAiReplyToStatefulMessage($finalReplyMessage, $aiReply);
                    $aiApplied = true;
                    $this->productMetrics->track(
                        ProductFunnels::CHATBOT,
                        'ai_sandbox_reply_applied',
                        'chatbot_ai_sandbox_reply_applied',
                        (int) ($company->id ?? 0),
                        null,
                        [
                            'conversation_id' => (int) $conversation->id,
                            'message_id' => (int) $inMessage->id,
                            'intent' => $intent,
                            'policy_action' => $action,
                        ],
                    );
                } else {
                    $error = 'ai_reply_unavailable';
                    $action = ChatbotAiPolicyService::ACTION_FALLBACK_LEGACY;
                }
            }

            $latencyMs = max(0, (int) floor((hrtime(true) - $startedNs) / 1_000_000));
            $normalizedGateResult = $this->mergeReplyComparison(
                $gateResult,
                $legacyReply,
                $aiReply,
                $finalReply,
                $aiApplied,
                $statefulHandled,
                $sandboxNumberAllowed,
            );
            $providerFromSuggestion = is_string($suggestionPayload['raw']['provider'] ?? null)
                ? trim((string) $suggestionPayload['raw']['provider'])
                : '';
            $modelFromSuggestion = is_string($suggestionPayload['raw']['model'] ?? null)
                ? trim((string) $suggestionPayload['raw']['model'])
                : '';
            $tokensUsed = $this->extractAssistiveTokensUsed($suggestionPayload['raw'] ?? null);

            $this->chatbotAiDecisionLogger->logDecision([
                'company_id' => (int) $company->id,
                'conversation_id' => (int) $conversation->id,
                'message_id' => (int) $inMessage->id,
                'user_id' => null,
                'mode' => $mode,
                'gate_result' => $normalizedGateResult,
                'intent' => $intent,
                'confidence' => $confidence,
                'action' => $action,
                'handoff_reason' => isset($policyDecision['handoff_reason']) && is_string($policyDecision['handoff_reason'])
                    ? $policyDecision['handoff_reason']
                    : null,
                'used_knowledge' => (bool) ($suggestionPayload['raw']['used_rag'] ?? false),
                'knowledge_refs' => $this->normalizeShadowKnowledgeRefs($suggestionPayload['raw']['rag_chunks'] ?? null),
                'latency_ms' => $latencyMs,
                'tokens_used' => $tokensUsed,
                'provider' => $providerFromSuggestion !== ''
                    ? $providerFromSuggestion
                    : ($provider !== '' ? $provider : null),
                'model' => $modelFromSuggestion !== ''
                    ? $modelFromSuggestion
                    : ($model !== '' ? $model : null),
                'error' => $error ?? ((str_starts_with($reason, 'provider_') || $reason === 'policy_exception') ? $reason : null),
            ]);

            return [$finalReply, $finalReplyMessage];
        } catch (Throwable $exception) {
            $latencyMs = max(0, (int) floor((hrtime(true) - $startedNs) / 1_000_000));

            try {
                $this->chatbotAiDecisionLogger->logDecision([
                    'company_id' => (int) $company->id,
                    'conversation_id' => (int) $conversation->id,
                    'message_id' => (int) $inMessage->id,
                    'user_id' => null,
                    'mode' => $mode,
                    'gate_result' => $this->mergeReplyComparison(
                        $gateResult,
                        $legacyReply,
                        null,
                        $legacyReply,
                        false,
                        $statefulHandled,
                        $sandboxNumberAllowed,
                    ),
                    'intent' => 'fallback',
                    'confidence' => 0.0,
                    'action' => ChatbotAiPolicyService::ACTION_FALLBACK_LEGACY,
                    'handoff_reason' => null,
                    'used_knowledge' => false,
                    'knowledge_refs' => null,
                    'latency_ms' => $latencyMs,
                    'tokens_used' => null,
                    'provider' => $provider !== '' ? $provider : null,
                    'model' => $model !== '' ? $model : null,
                    'error' => $exception->getMessage(),
                ]);
            } catch (Throwable $logException) {
                Log::warning('chatbot.ai_shadow_log_failed', [
                    'company_id' => (int) $company->id,
                    'conversation_id' => (int) $conversation->id,
                    'error' => $logException->getMessage(),
                ]);
            }

            return [$legacyReply, $replyMessage];
        }
    }

    /**
     * @param  mixed  $configuredTestNumbers
     */
    private function isSandboxTestNumberAllowed(mixed $configuredTestNumbers, string $normalizedFrom): bool
    {
        $numbers = [];

        if (is_array($configuredTestNumbers)) {
            $numbers = $configuredTestNumbers;
        } elseif (is_string($configuredTestNumbers)) {
            $numbers = preg_split('/[\n,;]+/', $configuredTestNumbers) ?: [];
        }

        if ($numbers === []) {
            return false;
        }

        foreach ($numbers as $rawNumber) {
            $candidate = $this->normalizePhone((string) $rawNumber);
            if ($candidate !== '' && $candidate === $normalizedFrom) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mantem a estrutura do payload stateful (menus, botoes/listas) e troca
     * apenas o texto de exibicao quando houver resposta assistida de IA.
     *
     * @param  array<string, mixed>|string|null  $replyMessage
     * @return array<string, mixed>|string|null
     */
    private function applyAiReplyToStatefulMessage(array|string|null $replyMessage, string $aiReply): array|string|null
    {
        if (! is_array($replyMessage)) {
            return $replyMessage;
        }

        $updated = $replyMessage;
        $msgType = trim((string) ($updated['type'] ?? ''));

        if ($msgType === 'interactive_buttons' || $msgType === 'interactive_list') {
            $updated['body_text'] = $aiReply;

            return $updated;
        }

        if ($msgType === 'text') {
            $updated['text'] = $aiReply;

            return $updated;
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $gateResult
     * @return array<string, mixed>
     */
    private function mergeReplyComparison(
        array $gateResult,
        string $legacyReply,
        ?string $aiReply,
        string $finalReply,
        bool $aiApplied,
        bool $statefulHandled,
        bool $sandboxNumberAllowed
    ): array {
        $gateResult['reply_comparison'] = [
            'legacy_reply' => $legacyReply,
            'ai_reply' => $aiReply,
            'final_reply' => $finalReply,
            'ai_applied' => $aiApplied,
            'stateful_handled' => $statefulHandled,
            'sandbox_number_allowed' => $sandboxNumberAllowed,
        ];

        return $gateResult;
    }

    /**
     * @return array{reply:string,raw:array<string,mixed>}|null
     */
    private function generateChatbotAiSuggestionPayload(Company $company, Conversation $conversation): ?array
    {
        $settings = $company->botSetting;
        if (! $settings) {
            return null;
        }

        try {
            $result = $this->chatbotAiSuggestion->generateSuggestion($conversation, $settings);
            $reply = ChatbotAiSuggestionResultNormalizer::toReplyText($result);
            if ($reply === null || trim($reply) === '') {
                return null;
            }

            return [
                'reply' => trim($reply),
                'raw' => is_array($result) ? $result : [],
            ];
        } catch (Throwable $exception) {
            Log::warning('chatbot.ai_reply_fallback', [
                'conversation_id' => (int) $conversation->id,
                'company_id' => (int) $company->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  mixed  $rawRefs
     * @return array<int, array<string, mixed>>|null
     */
    private function normalizeShadowKnowledgeRefs(mixed $rawRefs): ?array
    {
        if (! is_array($rawRefs)) {
            return null;
        }

        $normalized = [];
        foreach ($rawRefs as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized[] = [
                'title' => isset($item['title']) ? (string) $item['title'] : null,
                'score' => isset($item['score']) && is_numeric($item['score']) ? (float) $item['score'] : null,
            ];
        }

        return $normalized !== [] ? $normalized : null;
    }

    /**
     * @param  mixed  $suggestionRaw
     */
    private function extractAssistiveTokensUsed(mixed $suggestionRaw): ?int
    {
        if (! is_array($suggestionRaw)) {
            return null;
        }

        $meta = is_array($suggestionRaw['meta'] ?? null) ? $suggestionRaw['meta'] : [];
        $candidates = [
            $suggestionRaw['tokens_used'] ?? null,
            $meta['tokens_used'] ?? null,
            data_get($meta, 'usage.total_tokens'),
            data_get($meta, 'usage.tokens'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_numeric($candidate)) {
                continue;
            }

            $value = (int) $candidate;
            if ($value >= 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $gateResult
     * @return array<string, mixed>
     */
    private function mergeSafetyResult(array $gateResult, AiSafetyResult $safety): array
    {
        $gateResult['safety'] = [
            'blocked' => $safety->blocked,
            'stage' => $safety->blockStage,
            'reason' => $safety->blockReason,
            'flags' => $safety->flags,
        ];

        return $gateResult;
    }
}

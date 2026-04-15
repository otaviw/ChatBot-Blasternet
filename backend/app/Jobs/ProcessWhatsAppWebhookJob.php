<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Services\InboundMessageService;
use App\Services\RealtimePublisher;
use App\Support\MessageDeliveryStatus;
use App\Support\PhoneNumberNormalizer;
use App\Support\RealtimeEvents;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Intervalo entre tentativas (segundos).
     * Tenta novamente em 10s, depois em 60s.
     * Mantemos os valores abaixo do timeout (60s) para não conflitar com o
     * retry_after do Redis.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function __construct(
        private readonly array $changeValue,
        private readonly ?int $companyId,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(InboundMessageService $inboundMessage, RealtimePublisher $realtimePublisher): void
    {
        $company = $this->resolveCompany();

        if (! $company) {
            return;
        }

        $this->processStatuses($company, $this->changeValue, $realtimePublisher);
        $this->processTypingEvents($company, $this->changeValue, $realtimePublisher);

        Log::info('Webhook WhatsApp company resolvida por metadata.phone_number_id.', [
            'phone_number_id' => (string) (($this->changeValue['metadata'] ?? [])['phone_number_id'] ?? ''),
            'company_id' => $company->id,
        ]);

        $contactNameByWaId = [];
        foreach ($this->changeValue['contacts'] ?? [] as $contact) {
            $waId = trim((string) ($contact['wa_id'] ?? ''));
            $name = trim((string) ($contact['profile']['name'] ?? ''));
            if ($waId === '' || $name === '') {
                continue;
            }
            $contactNameByWaId[$waId] = $name;
        }

        foreach ($this->changeValue['messages'] ?? [] as $msg) {
            $from = (string) ($msg['from'] ?? '');
            $messageId = (string) ($msg['id'] ?? '');
            $messageType = (string) ($msg['type'] ?? '');
            $contactName = $contactNameByWaId[$from] ?? null;

            if (trim($from) === '') {
                continue;
            }

            // Deduplicação — camada 1 (job):
            // Verifica o wamid antes de qualquer chamada de serviço ou escrita no banco.
            // Cobre dois cenários:
            //   a) Reenvio da Meta: mesmo evento entregue em HTTP requests distintos
            //   b) Retry do job: falha após a mensagem já ter sido persistida
            // A checagem é feita antes do bootstrapConversation e do download de mídia
            // para evitar trabalho desnecessário.
            if ($messageType !== 'reaction' && $messageId !== '') {
                if (Message::where('whatsapp_message_id', $messageId)->exists()) {
                    Log::info('Webhook: mensagem duplicada ignorada no job.', [
                        'company_id' => (int) $company->id,
                        'wamid'      => $messageId,
                        'from'       => $from,
                        'type'       => $messageType,
                    ]);
                    continue;
                }
            }

            if ($messageType === 'reaction') {
                $this->processReaction($company, $msg, $realtimePublisher);
                continue;
            }

            if ($messageType === 'text') {
                $text = (string) ($msg['text']['body'] ?? '');
                if (trim($text) === '') {
                    continue;
                }

                $inboundMessage->handleIncomingText(
                    $company,
                    $from,
                    $text,
                    ['wamid' => $messageId, 'from' => $from, 'source' => 'webhook'],
                    ['source' => 'webhook'],
                    true,
                    $contactName
                );

                continue;
            }

            if ($messageType === 'image') {
                $mediaId = (string) ($msg['image']['id'] ?? '');
                $caption = (string) ($msg['image']['caption'] ?? '');
                if (trim($mediaId) === '') {
                    continue;
                }

                $inboundMessage->handleIncomingMedia(
                    $company, $from, $mediaId, $caption,
                    ['wamid' => $messageId, 'from' => $from, 'source' => 'webhook', 'incoming_type' => 'image'],
                    $contactName
                );

                Log::info('Webhook imagem processada.', ['company_id' => $company->id, 'from' => $from, 'message_id' => $messageId]);
                continue;
            }

            if ($messageType === 'audio') {
                $mediaId = (string) ($msg['audio']['id'] ?? '');
                $caption = (string) ($msg['audio']['caption'] ?? '');
                if (trim($mediaId) === '') {
                    continue;
                }

                $inboundMessage->handleIncomingMedia(
                    $company, $from, $mediaId, $caption,
                    ['wamid' => $messageId, 'from' => $from, 'source' => 'webhook', 'incoming_type' => 'audio'],
                    $contactName
                );

                Log::info('Webhook audio processado.', ['company_id' => $company->id, 'from' => $from, 'message_id' => $messageId]);
                continue;
            }

            if ($messageType === 'video') {
                $mediaId = (string) ($msg['video']['id'] ?? '');
                $caption = (string) ($msg['video']['caption'] ?? '');
                if (trim($mediaId) === '') {
                    continue;
                }

                $inboundMessage->handleIncomingMedia(
                    $company, $from, $mediaId, $caption,
                    ['wamid' => $messageId, 'from' => $from, 'source' => 'webhook', 'incoming_type' => 'video'],
                    $contactName
                );

                Log::info('Webhook video processado.', ['company_id' => $company->id, 'from' => $from]);
                continue;
            }

            if ($messageType === 'document') {
                $mediaId = (string) ($msg['document']['id'] ?? '');
                $caption = (string) ($msg['document']['caption'] ?? '');
                $filename = (string) ($msg['document']['filename'] ?? 'documento');
                if (trim($mediaId) === '') {
                    continue;
                }

                $inboundMessage->handleIncomingMedia(
                    $company, $from, $mediaId, $caption,
                    ['wamid' => $messageId, 'from' => $from, 'source' => 'webhook', 'incoming_type' => 'document', 'filename' => $filename],
                    $contactName
                );

                Log::info('Webhook document processado.', ['company_id' => $company->id, 'from' => $from]);
                continue;
            }

            if ($messageType === 'sticker') {
                $mediaId = (string) ($msg['sticker']['id'] ?? '');
                if (trim($mediaId) === '') {
                    continue;
                }

                $inboundMessage->handleIncomingMedia(
                    $company, $from, $mediaId, null,
                    ['wamid' => $messageId, 'from' => $from, 'source' => 'webhook', 'incoming_type' => 'sticker'],
                    $contactName
                );

                Log::info('Webhook sticker processado.', ['company_id' => $company->id]);
                continue;
            }

            if ($messageType === 'location') {
                $latitude = (float) ($msg['location']['latitude'] ?? 0);
                $longitude = (float) ($msg['location']['longitude'] ?? 0);
                $name = (string) ($msg['location']['name'] ?? '');
                $address = (string) ($msg['location']['address'] ?? '');

                $inboundMessage->handleIncomingLocation(
                    $company, $from, $latitude, $longitude, $name, $address,
                    ['wamid' => $messageId, 'from' => $from, 'source' => 'webhook', 'incoming_type' => 'location'],
                    $contactName
                );

                Log::info('Webhook location processado.', ['company_id' => $company->id]);
                continue;
            }
        }
    }

    private function resolveCompany(): ?Company
    {
        if ($this->companyId !== null) {
            return Company::with('botSetting')->find($this->companyId);
        }

        $metadata = is_array(Arr::get($this->changeValue, 'metadata')) ? $this->changeValue['metadata'] : [];
        $phoneNumberId = (string) ($metadata['phone_number_id'] ?? '');

        if ($phoneNumberId === '') {
            return null;
        }

        $company = Company::with('botSetting')
            ->where('meta_phone_number_id', $phoneNumberId)
            ->first();

        if (! $company) {
            Log::warning('Webhook WhatsApp sem company correspondente para phone_number_id.', [
                'phone_number_id' => $phoneNumberId,
            ]);
        }

        return $company;
    }

    private function processStatuses(Company $company, array $value, RealtimePublisher $realtimePublisher): void
    {
        $statuses = $value['statuses'] ?? null;
        if (! is_array($statuses) || $statuses === []) {
            return;
        }

        $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];

        Log::info('Webhook WhatsApp recebeu status de entrega.', [
            'company_id' => (int) $company->id,
            'statuses_count' => count($statuses),
            'phone_number_id' => $metadata['phone_number_id'] ?? null,
        ]);

        foreach ($statuses as $statusPayload) {
            if (! is_array($statusPayload)) {
                continue;
            }
            $this->applyStatusUpdate($company, $statusPayload, $metadata);
        }
    }

    private function processTypingEvents(Company $company, array $value, RealtimePublisher $realtimePublisher): void
    {
        $phoneCandidates = [];

        foreach (($value['messages'] ?? []) as $messagePayload) {
            if (! is_array($messagePayload)) {
                continue;
            }

            $messageType = mb_strtolower(trim((string) ($messagePayload['type'] ?? '')));
            if ($messageType !== 'typing') {
                continue;
            }

            $phoneCandidates[] = (string) ($messagePayload['from'] ?? '');
        }

        foreach (($value['statuses'] ?? []) as $statusPayload) {
            if (! is_array($statusPayload)) {
                continue;
            }

            $statusName = mb_strtolower(trim((string) ($statusPayload['status'] ?? '')));
            if (! str_contains($statusName, 'typing')) {
                continue;
            }

            $phoneCandidates[] = (string) ($statusPayload['recipient_id'] ?? $statusPayload['from'] ?? '');
        }

        foreach ($phoneCandidates as $phone) {
            $normalizedPhone = PhoneNumberNormalizer::normalizeBrazil($phone);
            if ($normalizedPhone === '') {
                continue;
            }

            $conversation = $this->resolveConversationForTyping($company, $normalizedPhone);
            if (! $conversation) {
                continue;
            }

            Cache::put("conversation:typing:{$company->id}:{$conversation->id}", now()->toISOString(), now()->addSeconds(5));

            $realtimePublisher->publish(
                RealtimeEvents::CUSTOMER_TYPING,
                [
                    "company:{$company->id}",
                    "conversation:{$conversation->id}",
                ],
                [
                    'conversationId' => (int) $conversation->id,
                    'companyId' => (int) $company->id,
                    'typing' => true,
                    'expires_in_ms' => 5000,
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $statusPayload
     * @param  array<string, mixed>  $metadata
     */
    private function applyStatusUpdate(Company $company, array $statusPayload, array $metadata): void
    {
        $statusName = mb_strtolower(trim((string) ($statusPayload['status'] ?? '')));
        $whatsAppMessageId = trim((string) ($statusPayload['id'] ?? ''));

        if ($statusName === '' || $whatsAppMessageId === '') {
            Log::warning('Webhook status ignorado por payload incompleto.', [
                'company_id' => (int) $company->id,
                'status' => $statusPayload['status'] ?? null,
                'status_id' => $statusPayload['id'] ?? null,
            ]);
            return;
        }

        $message = Message::query()
            ->where('whatsapp_message_id', $whatsAppMessageId)
            ->whereHas('conversation', function ($query) use ($company) {
                $query->where('company_id', (int) $company->id);
            })
            ->first();

        if (! $message) {
            Log::info('Webhook status recebido sem mensagem correspondente.', [
                'company_id' => (int) $company->id,
                'whatsapp_message_id' => $whatsAppMessageId,
                'status' => $statusName,
            ]);
            return;
        }

        $occurredAt = now();
        $rawTimestamp = $statusPayload['timestamp'] ?? null;
        if (is_numeric($rawTimestamp)) {
            $timestamp = (int) $rawTimestamp;
            if ($timestamp > 9999999999) {
                $timestamp = (int) floor($timestamp / 1000);
            }
            $occurredAt = now()->setTimestamp($timestamp);
        }

        $beforeStatus = (string) ($message->delivery_status ?: MessageDeliveryStatus::PENDING);
        switch ($statusName) {
            case MessageDeliveryStatus::SENT:
                $message->delivery_status = MessageDeliveryStatus::SENT;
                $message->sent_at = $message->sent_at ?: $occurredAt;
                $message->status_error = null;
                break;
            case MessageDeliveryStatus::DELIVERED:
                $message->delivery_status = MessageDeliveryStatus::DELIVERED;
                $message->delivered_at = $message->delivered_at ?: $occurredAt;
                $message->status_error = null;
                break;
            case MessageDeliveryStatus::READ:
                $message->delivery_status = MessageDeliveryStatus::READ;
                $message->read_at = $message->read_at ?: $occurredAt;
                $message->status_error = null;
                break;
            case MessageDeliveryStatus::FAILED:
                $message->delivery_status = MessageDeliveryStatus::FAILED;
                $message->failed_at = $message->failed_at ?: $occurredAt;
                $message->status_error = $this->formatStatusError($statusPayload['errors'] ?? null);
                break;
            default:
                Log::info('Webhook status ignorado por status nao mapeado.', [
                    'company_id' => (int) $company->id,
                    'message_id' => (int) $message->id,
                    'whatsapp_message_id' => $whatsAppMessageId,
                    'status' => $statusName,
                ]);
                return;
        }

        $message->status_meta = [
            'source' => 'webhook_status',
            'status' => $statusPayload,
            'metadata' => $metadata,
        ];

        if (! $message->isDirty()) {
            return;
        }

        $message->save();

        Log::info('Webhook status aplicado em mensagem.', [
            'company_id' => (int) $company->id,
            'message_id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'whatsapp_message_id' => $whatsAppMessageId,
            'from_status' => $beforeStatus,
            'to_status' => (string) $message->delivery_status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     */
    private function processReaction(Company $company, array $messagePayload, RealtimePublisher $realtimePublisher): void
    {
        $reactorPhone = PhoneNumberNormalizer::normalizeBrazil((string) ($messagePayload['from'] ?? ''));
        $reactionPayload = is_array($messagePayload['reaction'] ?? null) ? $messagePayload['reaction'] : [];
        $targetWhatsappMessageId = trim((string) ($reactionPayload['message_id'] ?? ''));
        $emoji = trim((string) ($reactionPayload['emoji'] ?? ''));

        if ($reactorPhone === '' || $targetWhatsappMessageId === '') {
            Log::warning('Webhook reaction ignorado por payload incompleto.', [
                'company_id' => (int) $company->id,
                'from' => $messagePayload['from'] ?? null,
                'reaction_message_id' => $reactionPayload['message_id'] ?? null,
                'reaction_emoji' => $reactionPayload['emoji'] ?? null,
            ]);
            return;
        }

        $message = Message::query()
            ->where('whatsapp_message_id', $targetWhatsappMessageId)
            ->whereHas('conversation', function ($query) use ($company) {
                $query->where('company_id', (int) $company->id);
            })
            ->first();

        if (! $message) {
            Log::warning('Webhook reaction recebido sem mensagem correspondente.', [
                'company_id' => (int) $company->id,
                'reactor_phone' => $reactorPhone,
                'target_whatsapp_message_id' => $targetWhatsappMessageId,
                'message_type' => $messagePayload['type'] ?? null,
                'message_id' => $messagePayload['id'] ?? null,
            ]);
            return;
        }

        if ($emoji === '') {
            $reaction = MessageReaction::query()
                ->where('message_id', (int) $message->id)
                ->where('reactor_phone', $reactorPhone)
                ->first();

            if (! $reaction) {
                return;
            }

            $reaction->delete();
            $this->publishMessageReactionsUpdated($company, $message, $realtimePublisher);
            return;
        }

        MessageReaction::updateOrCreate(
            ['message_id' => (int) $message->id, 'reactor_phone' => $reactorPhone],
            [
                'whatsapp_message_id' => $targetWhatsappMessageId,
                'emoji' => $emoji,
                'reacted_at' => $this->resolveMetaTimestamp($messagePayload['timestamp'] ?? null),
                'meta' => [
                    'source' => 'webhook_reaction',
                    'message_id' => $messagePayload['id'] ?? null,
                    'reaction' => $reactionPayload,
                ],
            ]
        );

        $this->publishMessageReactionsUpdated($company, $message, $realtimePublisher);
    }

    private function publishMessageReactionsUpdated(Company $company, Message $message, RealtimePublisher $realtimePublisher): void
    {
        $reactions = MessageReaction::query()
            ->where('message_id', (int) $message->id)
            ->orderBy('id')
            ->get(['reactor_phone', 'emoji', 'reacted_at'])
            ->map(fn (MessageReaction $reaction): array => [
                'reactor_phone' => (string) $reaction->reactor_phone,
                'emoji' => (string) ($reaction->emoji ?? ''),
                'reacted_at' => $reaction->reacted_at?->toISOString(),
            ])
            ->values()
            ->all();

        $realtimePublisher->publish(
            RealtimeEvents::MESSAGE_REACTIONS_UPDATED,
            ["company:{$company->id}", "conversation:{$message->conversation_id}"],
            [
                'conversation_id' => (int) $message->conversation_id,
                'message_id' => (int) $message->id,
                'reactions' => $reactions,
            ]
        );
    }

    private function resolveMetaTimestamp(mixed $rawTimestamp): ?Carbon
    {
        if (! is_numeric($rawTimestamp)) {
            return null;
        }

        $timestamp = (int) $rawTimestamp;
        if ($timestamp > 9999999999) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        return now()->setTimestamp($timestamp);
    }

    private function formatStatusError(mixed $errors): ?string
    {
        if (! is_array($errors) || ! isset($errors[0]) || ! is_array($errors[0])) {
            return null;
        }

        $first = $errors[0];
        $title = trim((string) ($first['title'] ?? ''));
        $msg = trim((string) ($first['message'] ?? ''));
        $code = trim((string) ($first['code'] ?? ''));
        $summary = trim(implode(' - ', array_filter([$title, $msg, $code])));

        return $summary !== '' ? $summary : null;
    }

    private function resolveConversationForTyping(Company $company, string $normalizedPhone): ?Conversation
    {
        $variants = PhoneNumberNormalizer::variantsForLookup($normalizedPhone);

        return Conversation::query()
            ->where('company_id', (int) $company->id)
            ->whereIn('customer_phone', $variants !== [] ? $variants : [$normalizedPhone])
            ->orderByDesc('id')
            ->first(['id', 'company_id', 'customer_phone']);
    }

    /**
     * Chamado pelo framework após esgotar todas as tentativas.
     * Loga contexto suficiente para investigação sem expor o payload completo.
     */
    public function failed(?\Throwable $exception): void
    {
        $metadata = is_array($this->changeValue['metadata'] ?? null)
            ? $this->changeValue['metadata']
            : [];

        Log::error('ProcessWhatsAppWebhookJob: falhou após todas as tentativas.', [
            'company_id'      => $this->companyId,
            'phone_number_id' => $metadata['phone_number_id'] ?? null,
            'messages_count'  => count($this->changeValue['messages'] ?? []),
            'statuses_count'  => count($this->changeValue['statuses'] ?? []),
            'attempts'        => $this->tries,
            'exception_class' => $exception !== null ? get_class($exception) : null,
            'exception'       => $exception?->getMessage(),
        ]);
    }
}

<?php

declare(strict_types=1);


namespace App\Observers;

use App\Logging\MetricsLogger;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Company\CompanyConversationCountersService;
use App\Services\NotificationDispatchService;
use App\Services\RealtimePublisher;
use App\Support\MessageDeliveryStatus;
use App\Support\RealtimeEvents;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class MessageObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private RealtimePublisher $publisher,
        private NotificationDispatchService $dispatchService,
        private CompanyConversationCountersService $countersService
    ) {}

    public function created(Message $message): void
    {
        $this->dispatchService->dispatchCustomerMessageNotification($message);

        $conversation = Conversation::query()
            ->whereKey($message->conversation_id)
            ->with([
                'assignedUser:id,name,email',
                'currentArea:id,name',
                'tags' => fn ($q) => $q->select('tags.id', 'tags.name', 'tags.color')->orderBy('tags.name'),
            ])
            ->withCount('messages')
            ->first([
                'id',
                'company_id',
                'customer_phone',
                'customer_name',
                'status',
                'handling_mode',
                'assigned_type',
                'assigned_id',
                'current_area_id',
                'created_at',
                'updated_at',
            ]);

        if (! $conversation || ! $conversation->company_id) {
            return;
        }

        MetricsLogger::message(
            direction: (string) $message->direction,
            contentType: (string) ($message->content_type ?: 'text'),
            senderType: (string) $message->type,
            conversationId: (int) $message->conversation_id,
            companyId: (int) $conversation->company_id,
        );

        $meta = is_array($message->meta) ? $message->meta : [];

        $this->publisher->publish(
            RealtimeEvents::MESSAGE_CREATED,
            [
                "company:{$conversation->company_id}",
                "conversation:{$message->conversation_id}",
            ],
            [
                'messageId' => (int) $message->id,
                'conversationId' => (int) $message->conversation_id,
                'companyId' => (int) $conversation->company_id,
                'direction' => (string) $message->direction,
                'type' => (string) $message->type,
                'contentType' => (string) ($message->content_type ?: 'text'),
                'text' => (string) ($message->text ?? ''),
                'mediaUrl' => $message->media_url,
                'mediaMimeType' => $message->media_mime_type,
                'mediaSizeBytes' => $message->media_size_bytes !== null ? (int) $message->media_size_bytes : null,
                'mediaWidth' => $message->media_width !== null ? (int) $message->media_width : null,
                'mediaHeight' => $message->media_height !== null ? (int) $message->media_height : null,
                'whatsappMessageId' => $message->whatsapp_message_id,
                'deliveryStatus' => (string) ($message->delivery_status ?: MessageDeliveryStatus::PENDING),
                'sentAt' => $message->sent_at?->toISOString(),
                'deliveredAt' => $message->delivered_at?->toISOString(),
                'readAt' => $message->read_at?->toISOString(),
                'failedAt' => $message->failed_at?->toISOString(),
                'createdAt' => $message->created_at?->toISOString(),
                'senderName' => isset($meta['actor_user_name']) && $meta['actor_user_name'] !== '' ? (string) $meta['actor_user_name'] : null,
                'conversation' => $this->serializeConversation(
                    $conversation,
                    (int) $message->id,
                    $message->created_at?->toISOString(),
                    $message->text,
                    (string) $message->direction,
                ),
            ],
            [
                'actorId' => isset($meta['actor_user_id']) ? (int) $meta['actor_user_id'] : null,
            ]
        );

        $this->publisher->publish(
            RealtimeEvents::CONVERSATION_COUNTERS_UPDATED,
            ["company:{$conversation->company_id}"],
            [
                'company_id' => (int) $conversation->company_id,
                'conversation_id' => (int) $conversation->id,
                'counters' => $this->countersService->buildFreshForCompany((int) $conversation->company_id),
            ]
        );
    }

    public function updated(Message $message): void
    {
        if (! $message->wasChanged([
            'delivery_status',
            'whatsapp_message_id',
            'sent_at',
            'delivered_at',
            'read_at',
            'failed_at',
            'status_error',
            'status_meta',
        ])) {
            return;
        }

        $conversation = Conversation::query()
            ->whereKey($message->conversation_id)
            ->first(['id', 'company_id']);

        if (! $conversation || ! $conversation->company_id) {
            return;
        }

        $this->publisher->publish(
            RealtimeEvents::MESSAGE_STATUS_UPDATED,
            [
                "company:{$conversation->company_id}",
                "conversation:{$message->conversation_id}",
            ],
            [
                'conversation_id' => (int) $message->conversation_id,
                'message_id' => (int) $message->id,
                'whatsapp_message_id' => $message->whatsapp_message_id,
                'delivery_status' => (string) ($message->delivery_status ?: MessageDeliveryStatus::PENDING),
                'sent_at' => $message->sent_at?->toISOString(),
                'delivered_at' => $message->delivered_at?->toISOString(),
                'read_at' => $message->read_at?->toISOString(),
                'failed_at' => $message->failed_at?->toISOString(),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversation(Conversation $conversation, int $lastMessageId, ?string $lastMessageAt, ?string $lastMessageText, string $lastMessageDirection): array
    {
        $tags = $conversation->relationLoaded('tags')
            ? $conversation->tags->map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name, 'color' => (string) $t->color])->values()->toArray()
            : [];

        return [
            'id' => (int) $conversation->id,
            'company_id' => (int) $conversation->company_id,
            'customer_phone' => (string) $conversation->customer_phone,
            'customer_name' => $conversation->customer_name,
            'status' => (string) $conversation->status,
            'handling_mode' => (string) $conversation->handling_mode,
            'assigned_type' => (string) $conversation->assigned_type,
            'assigned_id' => $conversation->assigned_id !== null ? (int) $conversation->assigned_id : null,
            'messages_count' => (int) ($conversation->messages_count ?? 0),
            'tags' => $tags,
            'assigned_user' => $conversation->assignedUser ? [
                'id' => (int) $conversation->assignedUser->id,
                'name' => (string) $conversation->assignedUser->name,
                'email' => (string) $conversation->assignedUser->email,
            ] : null,
            'current_area' => $conversation->currentArea ? [
                'id' => (int) $conversation->currentArea->id,
                'name' => (string) $conversation->currentArea->name,
            ] : null,
            'last_message_id' => $lastMessageId,
            'last_message_at' => $lastMessageAt,
            'last_message_text' => $lastMessageText,
            'last_message_direction' => $lastMessageDirection,
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
        ];
    }
}

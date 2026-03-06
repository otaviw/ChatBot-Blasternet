<?php

namespace App\Observers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\NotificationDispatchService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class MessageObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private RealtimePublisher $publisher,
        private NotificationDispatchService $dispatchService
    ) {}

    public function created(Message $message): void
    {
        $this->dispatchService->dispatchCustomerMessageNotification($message);

        $conversation = Conversation::query()
            ->whereKey($message->conversation_id)
            ->with(['assignedUser:id,name,email', 'currentArea:id,name'])
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
                'tags',
                'created_at',
                'updated_at',
            ]);

        if (! $conversation || ! $conversation->company_id) {
            return;
        }

        $meta = is_array($message->meta) ? $message->meta : [];

        $this->publisher->publish(
            'message.created',
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
                'createdAt' => $message->created_at?->toISOString(),
                'conversation' => $this->serializeConversation(
                    $conversation,
                    (int) $message->id,
                    $message->created_at?->toISOString()
                ),
            ],
            [
                'actorId' => isset($meta['actor_user_id']) ? (int) $meta['actor_user_id'] : null,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversation(Conversation $conversation, int $lastMessageId, ?string $lastMessageAt): array
    {
        $tags = is_array($conversation->tags) ? array_values($conversation->tags) : [];

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
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Observers;

use App\Models\Message;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class MessageObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private RealtimePublisher $publisher
    ) {}

    public function created(Message $message): void
    {
        $conversation = $message->relationLoaded('conversation')
            ? $message->conversation
            : $message->conversation()->first(['id', 'company_id']);

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
                'text' => (string) $message->text,
                'createdAt' => $message->created_at?->toISOString(),
            ],
            [
                'actorId' => isset($meta['actor_user_id']) ? (int) $meta['actor_user_id'] : null,
            ]
        );
    }
}

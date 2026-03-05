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
                'contentType' => (string) ($message->content_type ?: 'text'),
                'text' => (string) ($message->text ?? ''),
                'mediaUrl' => $message->media_url,
                'mediaMimeType' => $message->media_mime_type,
                'mediaSizeBytes' => $message->media_size_bytes !== null ? (int) $message->media_size_bytes : null,
                'mediaWidth' => $message->media_width !== null ? (int) $message->media_width : null,
                'mediaHeight' => $message->media_height !== null ? (int) $message->media_height : null,
                'createdAt' => $message->created_at?->toISOString(),
            ],
            [
                'actorId' => isset($meta['actor_user_id']) ? (int) $meta['actor_user_id'] : null,
            ]
        );
    }
}

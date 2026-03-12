<?php

namespace App\Observers;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Services\NotificationDispatchService;
use App\Services\RealtimePublisher;
use App\Support\RealtimeEvents;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ChatMessageObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly RealtimePublisher $publisher,
        private readonly NotificationDispatchService $dispatchService
    ) {}

    public function created(ChatMessage $message): void
    {
        $this->dispatchService->dispatchInternalChatMessageNotification($message);
        $this->publishMessageEvent(RealtimeEvents::MESSAGE_CREATED, $message, false);
    }

    public function updated(ChatMessage $message): void
    {
        if (! $message->wasChanged(['content', 'metadata', 'edited_at', 'deleted_at', 'updated_at'])) {
            return;
        }

        $this->publishMessageEvent(RealtimeEvents::MESSAGE_UPDATED, $message, true);
    }

    private function publishMessageEvent(string $event, ChatMessage $message, bool $includeSender): void
    {
        $message->load(['sender', 'attachments', 'conversation']);

        $this->publisher->publish(
            event: $event,
            rooms: $this->buildRooms($message, $includeSender),
            payload: $this->serializeMessage($message)
        );
    }

    /**
     * @return array<int, string>
     */
    private function buildRooms(ChatMessage $message, bool $includeSender): array
    {
        $rooms = ['chat:conversation:' . $message->conversation_id];

        $participantsQuery = $message->conversation->participants();
        if (! $includeSender) {
            $participantsQuery->where('user_id', '!=', $message->sender_id);
        }

        $participantIds = $participantsQuery->pluck('user_id');
        foreach ($participantIds as $userId) {
            $rooms[] = 'chat:user:' . $userId;
        }

        return array_values(array_unique($rooms));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(ChatMessage $message): array
    {
        $isDeleted = (bool) $message->deleted_at;

        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_id' => (int) $message->sender_id,
            'sender_name' => (string) ($message->sender?->name ?? 'Usuario'),
            'type' => (string) $message->type,
            'content' => $isDeleted ? 'Mensagem apagada' : (string) ($message->content ?? ''),
            'metadata' => is_array($message->metadata) ? $message->metadata : [],
            'attachments' => $isDeleted
                ? []
                : $message->attachments
                    ->map(fn (ChatAttachment $attachment): array => $this->serializeAttachment($attachment))
                    ->values()
                    ->all(),
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),
            'edited_at' => $message->edited_at?->toISOString(),
            'deleted_at' => $message->deleted_at?->toISOString(),
            'is_deleted' => $isDeleted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttachment(ChatAttachment $attachment): array
    {
        $attachmentId = (int) ($attachment->id ?? 0);
        $mediaUrl = $attachmentId > 0
            ? "/api/chat/attachments/{$attachmentId}/media"
            : (string) ($attachment->url ?? '');

        return [
            'id' => $attachmentId,
            'url' => $mediaUrl,
            'media_url' => $mediaUrl,
            'public_url' => (string) ($attachment->url ?? ''),
            'mime_type' => (string) $attachment->mime_type,
            'size_bytes' => (int) $attachment->size_bytes,
            'original_name' => (string) $attachment->original_name,
        ];
    }
}

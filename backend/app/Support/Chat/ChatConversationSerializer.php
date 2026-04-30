<?php

declare(strict_types=1);


namespace App\Support\Chat;

use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Carbon;

class ChatConversationSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serializeUser(User $user): array
    {
        return [
            'id'         => (int) $user->id,
            'name'       => (string) $user->name,
            'email'      => (string) $user->email,
            'role'       => User::normalizeRole((string) $user->role),
            'company_id' => $user->company_id ? (int) $user->company_id : null,
            'is_active'  => (bool) $user->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeParticipant(User $user): array
    {
        return [
            ...$this->serializeUser($user),
            'is_admin'  => (bool) ($user->pivot?->is_admin ?? false),
            'joined_at' => $this->toIsoStringOrNull($user->pivot?->joined_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeMessage(ChatMessage $message): array
    {
        $isDeleted = (bool) $message->deleted_at;
        $serializedAttachments = $isDeleted
            ? []
            : $message->attachments
                ->map(fn (ChatAttachment $attachment): array => $this->serializeAttachment($attachment))
                ->values()
                ->all();

        $metadata  = is_array($message->metadata) ? $message->metadata : [];
        $reactions = $metadata['reactions'] ?? [];

        return [
            'id'              => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_id'       => (int) $message->sender_id,
            'sender_name'     => (string) ($message->sender?->name ?? 'Usuário'),
            'type'            => (string) $message->type,
            'content'         => $isDeleted ? 'Mensagem apagada' : (string) ($message->content ?? ''),
            'metadata'        => $metadata,
            'reactions'       => $reactions,
            'attachments'     => $serializedAttachments,
            'created_at'      => $message->created_at?->toISOString(),
            'updated_at'      => $message->updated_at?->toISOString(),
            'edited_at'       => $message->edited_at?->toISOString(),
            'deleted_at'      => $message->deleted_at?->toISOString(),
            'is_deleted'      => $isDeleted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeAttachment(ChatAttachment $attachment): array
    {
        $mediaUrl = $this->attachmentMediaUrl($attachment);

        return [
            'id'            => (int) $attachment->id,
            'url'           => $mediaUrl,
            'media_url'     => $mediaUrl,
            'public_url'    => (string) ($attachment->url ?? ''),
            'mime_type'     => (string) $attachment->mime_type,
            'size_bytes'    => (int) $attachment->size_bytes,
            'original_name' => (string) $attachment->original_name,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $serializedMessages
     * @return array<int, array<string, mixed>>
     */
    public function enrichMessagesWithReadStatus(array $serializedMessages, ChatConversation $conversation): array
    {
        $participants      = $conversation->participants;
        $totalParticipants = $participants->count();

        return array_map(static function (array $msg) use ($participants, $totalParticipants): array {
            $senderId  = (int) ($msg['sender_id'] ?? 0);
            $createdAt = $msg['created_at'] ?? null;

            if (! $createdAt || $senderId <= 0) {
                $msg['read_by_count']    = 0;
                $msg['participant_count'] = $totalParticipants;

                return $msg;
            }

            $messageTimestamp = strtotime($createdAt);
            $readByCount      = 0;

            foreach ($participants as $participant) {
                if ((int) $participant->id === $senderId) {
                    continue;
                }

                $lastReadAt = $participant->pivot?->last_read_at;
                if ($lastReadAt && strtotime((string) $lastReadAt) >= $messageTimestamp) {
                    $readByCount++;
                }
            }

            $msg['read_by_count']    = $readByCount;
            $msg['participant_count'] = $totalParticipants;

            return $msg;
        }, $serializedMessages);
    }

    private function attachmentMediaUrl(ChatAttachment $attachment): string
    {
        $attachmentId = (int) ($attachment->id ?? 0);
        if ($attachmentId <= 0) {
            return (string) ($attachment->url ?? '');
        }

        return "/api/chat/attachments/{$attachmentId}/media";
    }

    private function toIsoStringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toISOString();
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Services\Chat;

use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalChatConversationService
{
    public function resolveAuthenticatedUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    public function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'authenticated' => false,
            'redirect' => '/entrar',
        ], 403);
    }

    public function findDirectConversation(int $userA, int $userB): ?ChatConversation
    {
        return ChatConversation::query()
            ->where('type', 'direct')
            ->whereHas('participants', function ($participantsQuery) use ($userA): void {
                $participantsQuery->where('users.id', $userA);
            })
            ->whereHas('participants', function ($participantsQuery) use ($userB): void {
                $participantsQuery->where('users.id', $userB);
            })
            ->whereDoesntHave('participants', function ($participantsQuery) use ($userA, $userB): void {
                $participantsQuery->whereNotIn('users.id', [$userA, $userB]);
            })
            ->first();
    }

    public function resolveRecipientId(Request $request): int
    {
        $rawId = $request->input('recipient_id')
            ?? $request->input('recipientId')
            ?? $request->input('user_id')
            ?? $request->input('userId');

        return (int) $rawId;
    }

    public function resolveConversationCompanyId(User $sender, User $recipient): ?int
    {
        $senderCompanyId = (int) ($sender->company_id ?? 0);
        if ($senderCompanyId > 0) {
            return $senderCompanyId;
        }

        $recipientCompanyId = (int) ($recipient->company_id ?? 0);

        return $recipientCompanyId > 0 ? $recipientCompanyId : null;
    }

    public function markConversationAsRead(ChatConversation $conversation, int $userId): void
    {
        $timestamp = now();
        $pivot = ChatParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('user_id', $userId)
            ->first();

        if ($pivot) {
            $pivot->last_read_at = $timestamp;
            $pivot->save();
            return;
        }

        ChatParticipant::query()->create([
            'conversation_id' => (int) $conversation->id,
            'user_id' => $userId,
            'joined_at' => $timestamp,
            'last_read_at' => $timestamp,
        ]);
    }

    public function isParticipant(ChatConversation $conversation, int $userId): bool
    {
        return $conversation->participants()
            ->where('user_id', $userId)
            ->exists();
    }

    public function belongsToConversation(ChatConversation $conversation, ChatMessage $message): bool
    {
        return (int) $message->conversation_id === (int) $conversation->id;
    }

    public function isImageFileMime(string $mimeType): bool
    {
        return str_starts_with(mb_strtolower(trim($mimeType)), 'image/');
    }

    public function loadConversationSummaryRelations(ChatConversation $conversation): void
    {
        $conversation->load([
            'participants:id,name,email,role,company_id,is_active',
            'lastMessage.sender:id,name,email,role,company_id,is_active',
            'lastMessage.attachments:id,message_id,url,mime_type,size_bytes,original_name',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeConversationSummary(ChatConversation $conversation, User $viewer): array
    {
        $lastMessage = $conversation->lastMessage;

        return [
            'id' => (int) $conversation->id,
            'type' => (string) $conversation->type,
            'created_by' => (int) $conversation->created_by,
            'company_id' => $conversation->company_id ? (int) $conversation->company_id : null,
            'participants' => $conversation->participants
                ->map(fn (User $participant): array => $this->serializeUser($participant))
                ->values()
                ->all(),
            'last_message' => $lastMessage ? $this->serializeMessage($lastMessage) : null,
            'last_message_at' => $lastMessage?->created_at?->toISOString()
                ?? $conversation->updated_at?->toISOString()
                ?? $conversation->created_at?->toISOString(),
            'unread_count' => $this->calculateUnreadCount($conversation, $viewer),
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeConversationDetail(ChatConversation $conversation, User $viewer): array
    {
        $summary = $this->serializeConversationSummary($conversation, $viewer);

        $serializedMessages = $conversation->messages
            ->map(fn (ChatMessage $message): array => $this->serializeMessage($message))
            ->values()
            ->all();

        $summary['messages'] = $this->enrichMessagesWithReadStatus($serializedMessages, $conversation);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeUser(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => User::normalizeRole((string) $user->role),
            'company_id' => $user->company_id ? (int) $user->company_id : null,
            'is_active' => (bool) $user->is_active,
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

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $reactions = $metadata['reactions'] ?? [];

        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_id' => (int) $message->sender_id,
            'sender_name' => (string) ($message->sender?->name ?? 'Usuario'),
            'type' => (string) $message->type,
            'content' => $isDeleted ? 'Mensagem apagada' : (string) ($message->content ?? ''),
            'metadata' => $metadata,
            'reactions' => $reactions,
            'attachments' => $serializedAttachments,
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),
            'edited_at' => $message->edited_at?->toISOString(),
            'deleted_at' => $message->deleted_at?->toISOString(),
            'is_deleted' => $isDeleted,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $serializedMessages
     * @return array<int, array<string, mixed>>
     */
    public function enrichMessagesWithReadStatus(array $serializedMessages, ChatConversation $conversation): array
    {
        $participants = $conversation->participants;
        $totalParticipants = $participants->count();

        return array_map(static function (array $msg) use ($participants, $totalParticipants): array {
            $senderId = (int) ($msg['sender_id'] ?? 0);
            $createdAt = $msg['created_at'] ?? null;

            if (! $createdAt || $senderId <= 0) {
                $msg['read_by_count'] = 0;
                $msg['participant_count'] = $totalParticipants;

                return $msg;
            }

            $messageTimestamp = strtotime($createdAt);
            $readByCount = 0;

            foreach ($participants as $participant) {
                if ((int) $participant->id === $senderId) {
                    continue;
                }

                $lastReadAt = $participant->pivot?->last_read_at;
                if ($lastReadAt && strtotime((string) $lastReadAt) >= $messageTimestamp) {
                    $readByCount++;
                }
            }

            $msg['read_by_count'] = $readByCount;
            $msg['participant_count'] = $totalParticipants;

            return $msg;
        }, $serializedMessages);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeAttachment(ChatAttachment $attachment): array
    {
        $mediaUrl = $this->chatAttachmentMediaUrl($attachment);

        return [
            'id' => (int) $attachment->id,
            'url' => $mediaUrl,
            'media_url' => $mediaUrl,
            'public_url' => (string) ($attachment->url ?? ''),
            'mime_type' => (string) $attachment->mime_type,
            'size_bytes' => (int) $attachment->size_bytes,
            'original_name' => (string) $attachment->original_name,
        ];
    }

    private function chatAttachmentMediaUrl(ChatAttachment $attachment): string
    {
        $attachmentId = (int) ($attachment->id ?? 0);
        if ($attachmentId <= 0) {
            return (string) ($attachment->url ?? '');
        }

        return "/api/chat/attachments/{$attachmentId}/media";
    }

    private function calculateUnreadCount(ChatConversation $conversation, User $viewer): int
    {
        $participant = $conversation->participants
            ->first(fn (User $item): bool => (int) $item->id === (int) $viewer->id);

        if (! $participant) {
            return 0;
        }

        $lastReadAt = $participant->pivot?->last_read_at;

        $query = ChatMessage::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('sender_id', '!=', (int) $viewer->id)
            ->whereNull('deleted_at');

        if ($lastReadAt) {
            $query->where('created_at', '>', $lastReadAt);
        }

        return (int) $query->count();
    }
}

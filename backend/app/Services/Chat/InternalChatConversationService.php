<?php

declare(strict_types=1);


namespace App\Services\Chat;

use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Support\Chat\ChatConversationSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class InternalChatConversationService
{
    public function __construct(
        private readonly ChatConversationSerializer $serializer,
    ) {}
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
            ->whereNull('deleted_at')
            ->whereHas('participants', function ($participantsQuery) use ($userA): void {
                $participantsQuery
                    ->where('users.id', $userA)
                    ->whereNull('chat_participants.left_at');
            })
            ->whereHas('participants', function ($participantsQuery) use ($userB): void {
                $participantsQuery
                    ->where('users.id', $userB)
                    ->whereNull('chat_participants.left_at');
            })
            ->whereDoesntHave('participants', function ($participantsQuery) use ($userA, $userB): void {
                $participantsQuery
                    ->whereNotIn('users.id', [$userA, $userB])
                    ->whereNull('chat_participants.left_at');
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
        $pivot = $this->activeParticipantQuery($conversation, $userId)->first();

        if ($pivot) {
            $pivot->last_read_at = $timestamp;
            $pivot->hidden_at = null;
            $pivot->save();
            return;
        }

        ChatParticipant::query()->create([
            'conversation_id' => (int) $conversation->id,
            'user_id' => $userId,
            'joined_at' => $timestamp,
            'last_read_at' => $timestamp,
            'is_admin' => false,
            'hidden_at' => null,
            'left_at' => null,
        ]);
    }

    public function isParticipant(ChatConversation $conversation, int $userId): bool
    {
        return $this->activeParticipantQuery($conversation, $userId)->exists();
    }

    public function isVisibleParticipant(ChatConversation $conversation, int $userId): bool
    {
        return $this->activeParticipantQuery($conversation, $userId)
            ->whereNull('hidden_at')
            ->exists();
    }

    public function isConversationDeleted(ChatConversation $conversation): bool
    {
        return (bool) $conversation->deleted_at;
    }

    public function findParticipantPivot(ChatConversation $conversation, int $userId): ?ChatParticipant
    {
        return ChatParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('user_id', $userId)
            ->first();
    }

    public function isGroupAdmin(ChatConversation $conversation, int $userId): bool
    {
        return $this->activeParticipantQuery($conversation, $userId)
            ->where('is_admin', true)
            ->exists();
    }

    public function countActiveParticipants(ChatConversation $conversation): int
    {
        return (int) ChatParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->whereNull('left_at')
            ->count();
    }

    public function countGroupAdmins(ChatConversation $conversation): int
    {
        return (int) ChatParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->whereNull('left_at')
            ->where('is_admin', true)
            ->count();
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

    public function clearConversationHiddenForActiveParticipants(ChatConversation $conversation): void
    {
        ChatParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->whereNull('left_at')
            ->update(['hidden_at' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeConversationSummary(
        ChatConversation $conversation,
        User $viewer,
        ?int $preloadedUnreadCount = null,
        ?bool $preloadedViewerIsAdmin = null
    ): array
    {
        $lastMessage = $conversation->lastMessage;
        $viewerPivot = $preloadedViewerIsAdmin === null
            ? ($this->findLoadedParticipantPivot($conversation, (int) $viewer->id)
                ?? $this->findParticipantPivot($conversation, (int) $viewer->id))
            : null;
        $viewerIsAdmin = $preloadedViewerIsAdmin ?? (bool) ($viewerPivot?->is_admin ?? false);
        $unreadCount = $preloadedUnreadCount ?? $this->calculateUnreadCount($conversation, $viewer);

        return [
            'id' => (int) $conversation->id,
            'type' => (string) $conversation->type,
            'name' => $conversation->name ? (string) $conversation->name : null,
            'created_by' => (int) $conversation->created_by,
            'company_id' => $conversation->company_id ? (int) $conversation->company_id : null,
            'participants' => $conversation->participants
                ->map(fn (User $participant): array => $this->serializeParticipant($participant))
                ->values()
                ->all(),
            'participant_count' => (int) $conversation->participants->count(),
            'current_user_is_admin' => $viewerIsAdmin,
            'last_message' => $lastMessage ? $this->serializeMessage($lastMessage) : null,
            'last_message_at' => $lastMessage?->created_at?->toISOString()
                ?? $conversation->updated_at?->toISOString()
                ?? $conversation->created_at?->toISOString(),
            'unread_count' => $unreadCount,
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
            'deleted_at' => $conversation->deleted_at?->toISOString(),
        ];
    }

    /**
     * @param  Collection<int, ChatConversation>  $conversations
     * @return array{
     *   unread_counts: array<int, int>,
     *   viewer_is_admin: array<int, bool>
     * }
     */
    public function preloadListContext(Collection $conversations, User $viewer): array
    {
        $conversationIds = $conversations
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        if ($conversationIds === []) {
            return [
                'unread_counts' => [],
                'viewer_is_admin' => [],
            ];
        }

        $participantRows = ChatParticipant::query()
            ->where('user_id', (int) $viewer->id)
            ->whereNull('left_at')
            ->whereIn('conversation_id', $conversationIds)
            ->get(['conversation_id', 'last_read_at', 'is_admin']);

        $viewerIsAdminByConversation = [];
        $conversationIdsWithoutLastReadAt = [];
        $cutoffsByConversation = [];

        foreach ($participantRows as $participant) {
            $conversationId = (int) $participant->conversation_id;
            $viewerIsAdminByConversation[$conversationId] = (bool) $participant->is_admin;

            if (! $participant->last_read_at) {
                $conversationIdsWithoutLastReadAt[] = $conversationId;
                continue;
            }

            $cutoffsByConversation[$conversationId] = $participant->last_read_at;
        }

        $unreadByConversation = [];
        $query = ChatMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('sender_id', '!=', (int) $viewer->id)
            ->whereNull('deleted_at')
            ->where(function ($scope) use ($conversationIdsWithoutLastReadAt, $cutoffsByConversation): void {
                if ($conversationIdsWithoutLastReadAt !== []) {
                    $scope->orWhereIn('conversation_id', array_values(array_unique($conversationIdsWithoutLastReadAt)));
                }

                foreach ($cutoffsByConversation as $conversationId => $lastReadAt) {
                    $scope->orWhere(function ($conversationScope) use ($conversationId, $lastReadAt): void {
                        $conversationScope
                            ->where('conversation_id', (int) $conversationId)
                            ->where('created_at', '>', $lastReadAt);
                    });
                }
            })
            ->selectRaw('conversation_id, COUNT(*) as total')
            ->groupBy('conversation_id')
            ->get();

        foreach ($query as $row) {
            $unreadByConversation[(int) $row->conversation_id] = (int) $row->total;
        }

        return [
            'unread_counts' => $unreadByConversation,
            'viewer_is_admin' => $viewerIsAdminByConversation,
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
        return $this->serializer->serializeUser($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeParticipant(User $user): array
    {
        return $this->serializer->serializeParticipant($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeMessage(ChatMessage $message): array
    {
        return $this->serializer->serializeMessage($message);
    }

    /**
     * @param  array<int, array<string, mixed>>  $serializedMessages
     * @return array<int, array<string, mixed>>
     */
    public function enrichMessagesWithReadStatus(array $serializedMessages, ChatConversation $conversation): array
    {
        return $this->serializer->enrichMessagesWithReadStatus($serializedMessages, $conversation);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeAttachment(ChatAttachment $attachment): array
    {
        return $this->serializer->serializeAttachment($attachment);
    }

    private function activeParticipantQuery(ChatConversation $conversation, int $userId): \Illuminate\Database\Eloquent\Builder
    {
        return ChatParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('user_id', $userId)
            ->whereNull('left_at');
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

    private function findLoadedParticipantPivot(ChatConversation $conversation, int $userId): ?ChatParticipant
    {
        if (! $conversation->relationLoaded('participants')) {
            return null;
        }

        $participant = $conversation->participants
            ->first(fn (User $item): bool => (int) $item->id === $userId);

        if (! $participant?->pivot) {
            return null;
        }

        $pivot = new ChatParticipant();
        $pivot->conversation_id = (int) $conversation->id;
        $pivot->user_id = $userId;
        $pivot->is_admin = (bool) $participant->pivot->is_admin;
        $pivot->last_read_at = $participant->pivot->last_read_at;

        return $pivot;
    }

    /**
     * @param  array<int, int>  $unreadCounts
     * @param  array<int, bool>  $viewerIsAdmin
     * @return array<string, mixed>
     */
    public function serializeConversationSummaryForList(
        ChatConversation $conversation,
        User $viewer,
        array $unreadCounts,
        array $viewerIsAdmin
    ): array {
        $conversationId = (int) $conversation->id;
        return $this->serializeConversationSummary(
            $conversation,
            $viewer,
            (int) ($unreadCounts[$conversationId] ?? 0),
            (bool) ($viewerIsAdmin[$conversationId] ?? false)
        );
    }

}

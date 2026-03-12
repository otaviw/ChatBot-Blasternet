<?php

namespace App\Observers;

use App\Models\Conversation;
use App\Models\ConversationTransfer;
use App\Models\Message;
use App\Services\NotificationDispatchService;
use App\Services\RealtimePublisher;
use App\Support\RealtimeEvents;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Carbon;

class ConversationTransferObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private RealtimePublisher $publisher,
        private NotificationDispatchService $dispatchService
    ) {}

    public function created(ConversationTransfer $transfer): void
    {
        $this->dispatchService->dispatchConversationTransferNotification($transfer);

        if (! $transfer->company_id || ! $transfer->conversation_id) {
            return;
        }

        $rooms = [
            "company:{$transfer->company_id}",
            "conversation:{$transfer->conversation_id}",
        ];

        if ($transfer->to_assigned_type === 'user' && $transfer->to_assigned_id) {
            $rooms[] = "user:{$transfer->to_assigned_id}";
        }

        if ($transfer->from_assigned_type === 'user' && $transfer->from_assigned_id) {
            $rooms[] = "user:{$transfer->from_assigned_id}";
        }

        if ($transfer->transferred_by_user_id) {
            $rooms[] = "user:{$transfer->transferred_by_user_id}";
        }

        $conversation = Conversation::query()
            ->whereKey($transfer->conversation_id)
            ->addSelect([
                'last_message_id' => Message::query()
                    ->select('id')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->latest('id')
                    ->limit(1),
                'last_message_at' => Message::query()
                    ->select('created_at')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->latest('id')
                    ->limit(1),
            ])
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

        $this->publisher->publish(
            RealtimeEvents::CONVERSATION_TRANSFERRED,
            $rooms,
            [
                'transferId' => (int) $transfer->id,
                'conversationId' => (int) $transfer->conversation_id,
                'companyId' => (int) $transfer->company_id,
                'fromAssignedType' => (string) $transfer->from_assigned_type,
                'fromAssignedId' => $transfer->from_assigned_id ? (int) $transfer->from_assigned_id : null,
                'toAssignedType' => (string) $transfer->to_assigned_type,
                'toAssignedId' => $transfer->to_assigned_id ? (int) $transfer->to_assigned_id : null,
                'transferredByUserId' => $transfer->transferred_by_user_id
                    ? (int) $transfer->transferred_by_user_id
                    : null,
                'createdAt' => $transfer->created_at?->toISOString(),
                'conversation' => $conversation ? $this->serializeConversation($conversation) : null,
            ],
            [
                'actorId' => $transfer->transferred_by_user_id ? (int) $transfer->transferred_by_user_id : null,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversation(Conversation $conversation): array
    {
        $tags = is_array($conversation->tags) ? array_values($conversation->tags) : [];
        $lastMessageAt = $conversation->last_message_at;
        $lastMessageAtIso = null;

        if ($lastMessageAt instanceof \DateTimeInterface) {
            $lastMessageAtIso = Carbon::instance($lastMessageAt)->toISOString();
        } elseif (is_string($lastMessageAt) && trim($lastMessageAt) !== '') {
            $lastMessageAtIso = Carbon::parse($lastMessageAt)->toISOString();
        }

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
            'last_message_id' => $conversation->last_message_id !== null ? (int) $conversation->last_message_id : null,
            'last_message_at' => $lastMessageAtIso,
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
        ];
    }
}

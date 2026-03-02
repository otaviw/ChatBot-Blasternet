<?php

namespace App\Observers;

use App\Models\ConversationTransfer;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ConversationTransferObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private RealtimePublisher $publisher
    ) {}

    public function created(ConversationTransfer $transfer): void
    {
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

        $this->publisher->publish(
            'conversation.transferred',
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
            ],
            [
                'actorId' => $transfer->transferred_by_user_id ? (int) $transfer->transferred_by_user_id : null,
            ]
        );
    }
}

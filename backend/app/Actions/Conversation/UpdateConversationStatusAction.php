<?php

namespace App\Actions\Conversation;

use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\NotificationDispatchService;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use Illuminate\Http\Request;

class UpdateConversationStatusAction
{
    public function __construct(
        private readonly NotificationDispatchService $dispatchService,
        private readonly AuditLogService $auditLog
    ) {}

    public function handle(Request $request, User $user, int $conversationId, string $operation): ?Conversation
    {
        if ($operation !== 'close') {
            return null;
        }

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return null;
        }

        $prevAssignedType = (string) $conversation->assigned_type;
        $prevAssignedId = $conversation->assigned_id ? (int) $conversation->assigned_id : null;

        $conversation->status = ConversationStatus::CLOSED;
        $conversation->handling_mode = ConversationHandlingMode::BOT;
        $conversation->assigned_type = ConversationAssignedType::UNASSIGNED;
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $conversation->closed_at = now();
        $conversation->save();

        $this->dispatchService->dispatchConversationClosedNotification(
            $conversation,
            $prevAssignedType,
            $prevAssignedId,
            (int) $user->id
        );

        $this->auditLog->record($request, 'company.conversation.closed', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'closed_by' => $user->id,
        ]);

        return $conversation;
    }

    public function reopen(Conversation $conversation, bool $resetRouting = false): Conversation
    {
        $conversation->status = ConversationStatus::OPEN;
        $conversation->closed_at = null;

        if ($resetRouting) {
            $conversation->handling_mode = ConversationHandlingMode::BOT;
            $conversation->assigned_type = ConversationAssignedType::UNASSIGNED;
            $conversation->assigned_id = null;
        }

        return $conversation;
    }
}


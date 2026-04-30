<?php

declare(strict_types=1);


namespace App\Actions\Conversation;

use App\Actions\Company\Conversation\AssumeCompanyConversationAction;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use Illuminate\Http\Request;

class AssignConversationAgentAction
{
    public function __construct(
        private readonly AssumeCompanyConversationAction $assumeAction,
        private readonly AuditLogService $auditLog
    ) {}

    public function handle(Request $request, User $user, int $conversationId, string $operation): ?Conversation
    {
        if ($operation === 'assume') {
            return $this->assumeAction->handle($request, $user, $conversationId);
        }

        if ($operation !== 'release') {
            return null;
        }

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return null;
        }

        $conversation->handling_mode = ConversationHandlingMode::BOT;
        $conversation->assigned_type = ConversationAssignedType::BOT;
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $conversation->status = ConversationStatus::OPEN;
        $conversation->save();

        $this->auditLog->record($request, 'company.conversation.released', $conversation->company_id, [
            'conversation_id' => $conversation->id,
        ]);

        return $conversation;
    }
}


<?php

namespace App\Actions\Company\Conversation;

use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Company\CompanyConversationSupportService;
use App\Support\ConversationAssignedType;
use App\Support\ConversationHandlingMode;
use App\Support\ConversationStatus;
use Illuminate\Http\Request;

class AssumeCompanyConversationAction
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly CompanyConversationSupportService $conversationSupport
    ) {}

    public function handle(Request $request, User $user, int $conversationId): ?Conversation
    {
        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return null;
        }

        $firstArea = $user->areas()->orderBy('name')->first(['areas.id', 'areas.name']);

        $conversation->handling_mode = ConversationHandlingMode::HUMAN;
        $conversation->assigned_type = ConversationAssignedType::USER;
        $conversation->assigned_id = (int) $user->id;
        $conversation->current_area_id = $firstArea?->id;
        $conversation->assigned_user_id = (int) $user->id;
        $conversation->assigned_area = $firstArea?->name;
        $conversation->assumed_at = now();
        $conversation->status = ConversationStatus::IN_PROGRESS;
        $conversation->save();

        $this->auditLog->record($request, 'company.conversation.assumed', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'assigned_type' => ConversationAssignedType::USER,
            'assigned_id' => $user->id,
        ]);

        $conversation->load(['assignedUser:id,name,email', 'currentArea:id,name']);
        $this->conversationSupport->normalizeConversationAssignmentRelations($conversation);

        return $conversation;
    }
}

<?php

namespace App\Actions\Company\Conversation;

use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Company\CompanyConversationSupportService;
use App\Services\TransferConversationService;
use Illuminate\Http\Request;

class TransferCompanyConversationAction
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly TransferConversationService $transferService,
        private readonly CompanyConversationSupportService $conversationSupport
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    public function handle(Request $request, User $user, int $conversationId, array $validated): ?array
    {
        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return null;
        }

        [$targetType, $targetId] = $this->conversationSupport->resolveTransferTargetFromLegacyPayload(
            (int) $user->company_id,
            $validated
        );

        $result = $this->transferService->transfer(
            $conversation,
            $user,
            $targetType,
            $targetId,
            (bool) ($validated['send_outbound'] ?? true)
        );

        $transfer = $result['transfer'];
        $this->auditLog->record($request, 'company.conversation.transferred', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'from_assigned_type' => $transfer->from_assigned_type,
            'from_assigned_id' => $transfer->from_assigned_id,
            'to_assigned_type' => $transfer->to_assigned_type,
            'to_assigned_id' => $transfer->to_assigned_id,
            'auto_accepted' => true,
        ]);

        $updatedConversation = Conversation::query()
            ->whereKey($conversation->id)
            ->with(['assignedUser:id,name,email', 'currentArea:id,name'])
            ->firstOrFail();

        $this->conversationSupport->normalizeConversationAssignmentRelations($updatedConversation);
        $history = $this->conversationSupport->loadTransferHistory($updatedConversation);

        return [
            'ok' => true,
            'was_sent' => $result['was_sent'],
            'message' => $result['message'],
            'system_message' => $result['message'],
            'transfer' => $transfer,
            'conversation' => $updatedConversation,
            'transfer_history' => $history,
        ];
    }
}

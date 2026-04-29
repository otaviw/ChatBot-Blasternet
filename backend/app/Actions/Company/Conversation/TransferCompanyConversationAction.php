<?php

namespace App\Actions\Company\Conversation;

use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Company\CompanyConversationSupportService;
use App\Services\ProductMetricsService;
use App\Services\TransferConversationService;
use App\Support\ProductFunnels;
use Illuminate\Http\Request;

class TransferCompanyConversationAction
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly TransferConversationService $transferService,
        private readonly CompanyConversationSupportService $conversationSupport,
        private readonly ProductMetricsService $productMetrics,
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

        $this->productMetrics->track(
            ProductFunnels::TRANSFERENCIA,
            'requested',
            'conversation_transfer_requested',
            (int) $conversation->company_id,
            (int) $user->id,
            [
                'conversation_id' => (int) $conversation->id,
                'target_type' => (string) $targetType,
                'target_id' => (int) $targetId,
            ],
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

        $this->productMetrics->track(
            ProductFunnels::TRANSFERENCIA,
            'completed',
            'conversation_transfer_completed',
            (int) $conversation->company_id,
            (int) $user->id,
            [
                'conversation_id' => (int) $conversation->id,
                'to_assigned_type' => (string) $transfer->to_assigned_type,
                'to_assigned_id' => $transfer->to_assigned_id ? (int) $transfer->to_assigned_id : null,
                'was_sent' => (bool) ($result['was_sent'] ?? false),
            ],
        );

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

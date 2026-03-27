<?php

namespace App\Actions\Company\Conversation;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use App\Services\Company\CompanyConversationSupportService;
use App\Services\ConversationInactivityService;
use App\Services\TransferConversationService;
use Illuminate\Http\Request;

class ShowCompanyConversationAction
{
    public function __construct(
        private readonly ConversationInactivityService $conversationInactivityService,
        private readonly TransferConversationService $transferService,
        private readonly CompanyConversationSupportService $conversationSupport,
        private readonly AiAccessService $aiAccessService
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(User $user, int $conversationId, Request $request): ?array
    {
        $companyId = (int) $user->company_id;
        $this->conversationInactivityService->closeInactiveConversations($companyId);
        $settings = $this->aiAccessService->resolveCompanySettings($user);
        $canUseInternalAi = $this->aiAccessService->canUseInternalAi($user, $settings);

        $messagesPerPage = min(50, max(10, (int) $request->query('messages_per_page', 25)));
        $messagesPageParam = $request->query('messages_page');

        $showQuery = Conversation::query()
            ->where('company_id', $companyId)
            ->whereKey($conversationId)
            ->with(['assignedUser:id,name,email', 'currentArea:id,name']);
        $this->conversationSupport->applyInboxVisibilityScope($showQuery, $user);

        $conversation = $showQuery->first();
        if (! $conversation) {
            return null;
        }

        $messagesQuery = $conversation->messages()
            ->with(['reactions' => function ($query) {
                $query->orderBy('id')
                    ->select(['id', 'message_id', 'reactor_phone', 'emoji', 'reacted_at']);
            }])
            ->orderBy('id', 'asc');
        $totalMessages = $conversation->messages()->count();
        $lastMessagesPage = $totalMessages > 0 ? (int) ceil($totalMessages / $messagesPerPage) : 1;
        $messagesPage = $messagesPageParam !== null && $messagesPageParam !== ''
            ? max(1, min((int) $messagesPageParam, $lastMessagesPage))
            : $lastMessagesPage;
        $messagesPaginator = $messagesQuery->paginate($messagesPerPage, ['*'], 'messages_page', $messagesPage);
        $conversation->setRelation('messages', $messagesPaginator->getCollection());

        $this->conversationSupport->normalizeConversationAssignmentRelations($conversation);
        $transferHistory = $this->conversationSupport->loadTransferHistory($conversation);

        return [
            'authenticated' => true,
            'role' => 'company',
            'can_use_ai' => $canUseInternalAi,
            'can_access_internal_ai_chat' => $canUseInternalAi,
            'conversation' => $conversation,
            'transfer_history' => $transferHistory,
            'transfer_options' => $this->transferService->transferOptions($companyId),
            'messages_pagination' => [
                'current_page' => $messagesPaginator->currentPage(),
                'last_page' => $messagesPaginator->lastPage(),
                'per_page' => $messagesPaginator->perPage(),
                'total' => $messagesPaginator->total(),
            ],
        ];
    }
}

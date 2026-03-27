<?php

namespace App\Actions\Company\Ai;

use App\Models\User;
use App\Services\Ai\InternalAiConversationService;
use Illuminate\Http\Request;

class ShowCompanyAiConversationAction
{
    public function __construct(
        private readonly InternalAiConversationService $conversationService
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(User $user, int $conversationId, Request $request): ?array
    {
        $this->conversationService->ensureInternalChatEnabled($user);

        $conversation = $this->conversationService->findForUser($user, $conversationId);
        if (! $conversation) {
            return null;
        }

        $messagesPerPage = min(100, max(10, (int) $request->query('messages_per_page', 30)));
        $messagesPageParam = $request->query('messages_page');

        $totalMessages = (int) $conversation->messages()->count();
        $lastMessagesPage = $totalMessages > 0 ? (int) ceil($totalMessages / $messagesPerPage) : 1;
        $messagesPage = $messagesPageParam !== null && $messagesPageParam !== ''
            ? max(1, min((int) $messagesPageParam, $lastMessagesPage))
            : $lastMessagesPage;

        $messagesPaginator = $conversation->messages()
            ->paginate($messagesPerPage, ['*'], 'messages_page', $messagesPage);

        $conversation->load('lastMessage');
        $conversation->setRelation('messages', $messagesPaginator->getCollection());

        return [
            'authenticated' => true,
            'role' => 'company',
            'conversation' => $this->conversationService->serializeConversationDetail($conversation),
            'messages_pagination' => $this->conversationService->paginationPayload($messagesPaginator),
        ];
    }
}

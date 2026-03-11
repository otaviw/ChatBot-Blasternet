<?php

namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowConversationAction
{
    public function __construct(
        private readonly InternalChatConversationService $chatService
    ) {}

    public function handle(Request $request, ChatConversation $conversation): JsonResponse
    {
        $user = $this->chatService->resolveAuthenticatedUser($request);
        if (! $user) {
            return $this->chatService->unauthenticatedResponse();
        }

        if (! $this->chatService->isParticipant($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Sem permissao para acessar esta conversa.',
            ], 403);
        }

        $messagesPerPage = (int) $request->query('messages_per_page', 0);
        if ($messagesPerPage <= 0) {
            // Backward compatibility with legacy clients that still send messages_limit.
            $messagesPerPage = (int) $request->query('messages_limit', 120);
        }
        $messagesPerPage = min(max($messagesPerPage, 1), 300);

        $messagesPageParam = $request->query('messages_page');
        $totalMessages = ChatMessage::query()
            ->where('conversation_id', (int) $conversation->id)
            ->count();

        $lastMessagesPage = $totalMessages > 0 ? (int) ceil($totalMessages / $messagesPerPage) : 1;
        $messagesPage = $messagesPageParam !== null && $messagesPageParam !== ''
            ? max(1, min((int) $messagesPageParam, $lastMessagesPage))
            : $lastMessagesPage;

        $messagesPaginator = ChatMessage::query()
            ->where('conversation_id', (int) $conversation->id)
            ->with([
                'sender:id,name,email,role,company_id,is_active',
                'attachments:id,message_id,url,mime_type,size_bytes,original_name',
            ])
            ->orderBy('id')
            ->paginate($messagesPerPage, ['*'], 'messages_page', $messagesPage);

        $this->chatService->loadConversationSummaryRelations($conversation);
        $conversation->setRelation('messages', $messagesPaginator->getCollection());

        return response()->json([
            'authenticated' => true,
            'conversation' => $this->chatService->serializeConversationDetail($conversation, $user),
            'messages_pagination' => [
                'current_page' => (int) $messagesPaginator->currentPage(),
                'last_page' => (int) $messagesPaginator->lastPage(),
                'per_page' => (int) $messagesPaginator->perPage(),
                'total' => (int) $messagesPaginator->total(),
            ],
        ]);
    }
}

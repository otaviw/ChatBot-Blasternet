<?php

namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListConversationsAction
{
    public function __construct(
        private readonly InternalChatConversationService $chatService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $user = $this->chatService->resolveAuthenticatedUser($request);
        if (! $user) {
            return $this->chatService->unauthenticatedResponse();
        }

        $search = trim((string) $request->query('search', ''));
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $query = ChatConversation::query()
            ->whereHas('participants', function ($participantsQuery) use ($user): void {
                $participantsQuery->where('users.id', (int) $user->id);
            })
            ->with([
                'participants:id,name,email,role,company_id,is_active',
                'lastMessage.sender:id,name,email,role,company_id,is_active',
                'lastMessage.attachments:id,message_id,url,mime_type,size_bytes,original_name',
            ])
            ->orderByRaw("
                COALESCE(
                    (SELECT MAX(cm.created_at) FROM chat_messages cm WHERE cm.conversation_id = chat_conversations.id),
                    chat_conversations.updated_at,
                    chat_conversations.created_at
                ) DESC
            ")
            ->orderByDesc('chat_conversations.id');

        if ($search !== '') {
            $query->where(function ($scopedQuery) use ($search): void {
                $scopedQuery->whereHas('participants', function ($participantsQuery) use ($search): void {
                    $participantsQuery
                        ->where('users.name', 'like', '%'.$search.'%')
                        ->orWhere('users.email', 'like', '%'.$search.'%');
                });

                if (ctype_digit($search)) {
                    $scopedQuery->orWhere('chat_conversations.id', (int) $search);
                }
            });
        }

        $pagination = $query->paginate($perPage)->withQueryString();

        $conversations = collect($pagination->items())
            ->map(function (ChatConversation $conversation) use ($user): array {
                return $this->chatService->serializeConversationSummary($conversation, $user);
            })
            ->values()
            ->all();

        return response()->json([
            'authenticated' => true,
            'conversations' => $conversations,
            'conversations_pagination' => [
                'current_page' => (int) $pagination->currentPage(),
                'last_page' => (int) $pagination->lastPage(),
                'per_page' => (int) $pagination->perPage(),
                'total' => (int) $pagination->total(),
            ],
        ]);
    }
}

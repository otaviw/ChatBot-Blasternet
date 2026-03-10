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

        $messagesLimit = min(max((int) $request->query('messages_limit', 120), 1), 300);
        $messages = ChatMessage::query()
            ->where('conversation_id', (int) $conversation->id)
            ->with([
                'sender:id,name,email,role,company_id,is_active',
                'attachments:id,message_id,url,mime_type,size_bytes,original_name',
            ])
            ->orderByDesc('id')
            ->limit($messagesLimit)
            ->get()
            ->reverse()
            ->values();

        $this->chatService->loadConversationSummaryRelations($conversation);
        $conversation->setRelation('messages', $messages);

        return response()->json([
            'authenticated' => true,
            'conversation' => $this->chatService->serializeConversationDetail($conversation, $user),
        ]);
    }
}

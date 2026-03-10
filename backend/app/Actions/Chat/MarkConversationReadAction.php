<?php

namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarkConversationReadAction
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
                'message' => 'Sem permissao para marcar leitura desta conversa.',
            ], 403);
        }

        $this->chatService->markConversationAsRead($conversation, (int) $user->id);

        return response()->json([
            'ok' => true,
            'conversation_id' => (int) $conversation->id,
            'unread_count' => 0,
        ]);
    }
}

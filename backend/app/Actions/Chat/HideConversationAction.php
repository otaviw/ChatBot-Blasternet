<?php

declare(strict_types=1);


namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HideConversationAction
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

        if ($this->chatService->isConversationDeleted($conversation)) {
            return response()->json([
                'message' => 'Conversa não encontrada.',
            ], 404);
        }

        if ((string) $conversation->type !== 'direct') {
            return response()->json([
                'message' => 'Apenas conversas diretas podem ser apagadas individualmente.',
            ], 422);
        }

        $participant = ChatParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('user_id', (int) $user->id)
            ->whereNull('left_at')
            ->first();

        if (! $participant) {
            return response()->json([
                'message' => 'Sem permissão para apagar esta conversa.',
            ], 403);
        }

        $participant->hidden_at = now();
        $participant->save();

        return response()->json([
            'ok' => true,
            'conversation_id' => (int) $conversation->id,
            'hidden' => true,
        ]);
    }
}

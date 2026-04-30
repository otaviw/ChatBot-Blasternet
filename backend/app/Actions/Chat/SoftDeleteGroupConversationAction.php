<?php

declare(strict_types=1);


namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SoftDeleteGroupConversationAction
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
                'message' => 'Grupo não encontrado.',
            ], 404);
        }

        if ((string) $conversation->type !== 'group') {
            return response()->json([
                'message' => 'Apenas grupos podem ser apagados.',
            ], 422);
        }

        if (! $this->chatService->isVisibleParticipant($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Sem permissão para apagar este grupo.',
            ], 403);
        }

        if (! $this->chatService->isGroupAdmin($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Somente admins podem apagar o grupo.',
            ], 403);
        }

        $conversation->deleted_at = now();
        $conversation->save();

        return response()->json([
            'ok' => true,
            'conversation_id' => (int) $conversation->id,
            'deleted' => true,
        ]);
    }
}

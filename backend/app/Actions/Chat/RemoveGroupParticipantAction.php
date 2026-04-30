<?php

declare(strict_types=1);


namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RemoveGroupParticipantAction
{
    public function __construct(
        private readonly InternalChatConversationService $chatService
    ) {}

    public function handle(Request $request, ChatConversation $conversation, User $participant): JsonResponse
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
                'message' => 'Apenas grupos permitem remover participantes.',
            ], 422);
        }

        if (! $this->chatService->isVisibleParticipant($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Sem permissão para alterar este grupo.',
            ], 403);
        }

        if (! $this->chatService->isGroupAdmin($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Somente admins podem remover participantes.',
            ], 403);
        }

        if ((int) $participant->id === (int) $user->id) {
            throw ValidationException::withMessages([
                'participant_id' => ['Use a opção sair do grupo para remover a si mesmo.'],
            ]);
        }

        $targetPivot = ChatParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('user_id', (int) $participant->id)
            ->whereNull('left_at')
            ->first();

        if (! $targetPivot) {
            return response()->json([
                'message' => 'Participante não encontrado no grupo.',
            ], 404);
        }

        if ((bool) $targetPivot->is_admin && $this->chatService->countGroupAdmins($conversation) <= 1) {
            throw ValidationException::withMessages([
                'participant_id' => ['O grupo precisa manter ao menos um admin ativo.'],
            ]);
        }

        $targetPivot->is_admin = false;
        $targetPivot->left_at = now();
        $targetPivot->hidden_at = now();
        $targetPivot->save();

        $conversation->refresh();
        $this->chatService->loadConversationSummaryRelations($conversation);

        return response()->json([
            'ok' => true,
            'conversation' => $this->chatService->serializeConversationSummary($conversation, $user),
            'removed_user_id' => (int) $participant->id,
        ]);
    }
}

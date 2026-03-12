<?php

namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UpdateGroupParticipantAdminAction
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
                'message' => 'Grupo nao encontrado.',
            ], 404);
        }

        if ((string) $conversation->type !== 'group') {
            return response()->json([
                'message' => 'Apenas grupos permitem alterar permissao de admin.',
            ], 422);
        }

        if (! $this->chatService->isVisibleParticipant($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Sem permissao para alterar este grupo.',
            ], 403);
        }

        if (! $this->chatService->isGroupAdmin($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Somente admins podem alterar permissao de admin.',
            ], 403);
        }

        $isAdminRaw = $request->input('is_admin');
        if ($isAdminRaw === null) {
            $isAdminRaw = $request->input('isAdmin', $request->input('admin'));
        }

        if ($isAdminRaw === null) {
            throw ValidationException::withMessages([
                'is_admin' => ['Informe se o participante deve ser admin.'],
            ]);
        }

        $makeAdmin = filter_var($isAdminRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($makeAdmin === null) {
            throw ValidationException::withMessages([
                'is_admin' => ['Valor invalido para permissao de admin.'],
            ]);
        }

        $targetPivot = ChatParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('user_id', (int) $participant->id)
            ->whereNull('left_at')
            ->first();

        if (! $targetPivot) {
            return response()->json([
                'message' => 'Participante nao encontrado no grupo.',
            ], 404);
        }

        if (! $makeAdmin && (bool) $targetPivot->is_admin && $this->chatService->countGroupAdmins($conversation) <= 1) {
            throw ValidationException::withMessages([
                'is_admin' => ['O grupo precisa manter ao menos um admin ativo.'],
            ]);
        }

        $targetPivot->is_admin = $makeAdmin;
        $targetPivot->save();

        $conversation->refresh();
        $this->chatService->loadConversationSummaryRelations($conversation);

        return response()->json([
            'ok' => true,
            'conversation' => $this->chatService->serializeConversationSummary($conversation, $user),
        ]);
    }
}

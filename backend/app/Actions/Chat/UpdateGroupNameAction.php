<?php

namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UpdateGroupNameAction
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
                'message' => 'Grupo nao encontrado.',
            ], 404);
        }

        if ((string) $conversation->type !== 'group') {
            return response()->json([
                'message' => 'Apenas grupos permitem alteracao de nome.',
            ], 422);
        }

        if (! $this->chatService->isVisibleParticipant($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Sem permissao para alterar este grupo.',
            ], 403);
        }

        if (! $this->chatService->isGroupAdmin($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Somente admins podem alterar o nome do grupo.',
            ], 403);
        }

        $name = trim((string) ($request->input('name') ?? $request->input('group_name') ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['Informe o novo nome do grupo.'],
            ]);
        }

        if (mb_strlen($name) > 120) {
            throw ValidationException::withMessages([
                'name' => ['O nome do grupo deve ter no maximo 120 caracteres.'],
            ]);
        }

        $conversation->name = $name;
        $conversation->save();

        $conversation->refresh();
        $this->chatService->loadConversationSummaryRelations($conversation);

        return response()->json([
            'ok' => true,
            'conversation' => $this->chatService->serializeConversationSummary($conversation, $user),
        ]);
    }
}

<?php

namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveGroupConversationAction
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
                'message' => 'Apenas grupos permitem sair da conversa.',
            ], 422);
        }

        $currentPivot = ChatParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('user_id', (int) $user->id)
            ->whereNull('left_at')
            ->first();

        if (! $currentPivot) {
            return response()->json([
                'message' => 'Voce nao participa deste grupo.',
            ], 403);
        }

        $transferAdminTo = (int) ($request->input('transfer_admin_to')
            ?? $request->input('transferAdminTo')
            ?? 0);

        DB::transaction(function () use ($conversation, $currentPivot, $transferAdminTo): void {
            $isCurrentAdmin = (bool) $currentPivot->is_admin;
            if ($isCurrentAdmin && $this->chatService->countGroupAdmins($conversation) <= 1) {
                if ($transferAdminTo <= 0 || $transferAdminTo === (int) $currentPivot->user_id) {
                    throw ValidationException::withMessages([
                        'transfer_admin_to' => ['Transfira a administracao para outro participante antes de sair.'],
                    ]);
                }

                $targetPivot = ChatParticipant::query()
                    ->where('conversation_id', (int) $conversation->id)
                    ->where('user_id', $transferAdminTo)
                    ->whereNull('left_at')
                    ->first();

                if (! $targetPivot) {
                    throw ValidationException::withMessages([
                        'transfer_admin_to' => ['Participante escolhido para transferencia nao foi encontrado.'],
                    ]);
                }

                $targetPivot->is_admin = true;
                $targetPivot->save();
            }

            $currentPivot->is_admin = false;
            $currentPivot->left_at = now();
            $currentPivot->hidden_at = now();
            $currentPivot->save();

            $remainingParticipants = $this->chatService->countActiveParticipants($conversation);
            if ($remainingParticipants <= 0 && ! $conversation->deleted_at) {
                $conversation->deleted_at = now();
                $conversation->save();
            }
        });

        return response()->json([
            'ok' => true,
            'conversation_id' => (int) $conversation->id,
            'left' => true,
        ]);
    }
}

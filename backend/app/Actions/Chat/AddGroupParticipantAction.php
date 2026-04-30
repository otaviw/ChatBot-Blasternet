<?php

declare(strict_types=1);


namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Policies\ChatPolicy;
use App\Services\Chat\InternalChatConversationService;
use App\Services\NotificationDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddGroupParticipantAction
{
    public function __construct(
        private readonly InternalChatConversationService $chatService,
        private readonly ChatPolicy $chatPolicy,
        private readonly NotificationDispatchService $dispatchService
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
                'message' => 'Apenas grupos permitem adicionar participantes.',
            ], 422);
        }

        if (! $this->chatService->isVisibleParticipant($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Sem permissão para alterar este grupo.',
            ], 403);
        }

        if (! $this->chatService->isGroupAdmin($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Somente admins podem adicionar participantes.',
            ], 403);
        }

        $participantId = (int) ($request->input('participant_id')
            ?? $request->input('participantId')
            ?? $request->input('user_id')
            ?? $request->input('userId')
            ?? 0);

        if ($participantId <= 0) {
            throw ValidationException::withMessages([
                'participant_id' => ['Informe um participante valido.'],
            ]);
        }

        $participantUser = User::query()
            ->where('id', $participantId)
            ->where('is_active', true)
            ->first();

        if (! $participantUser instanceof User) {
            throw ValidationException::withMessages([
                'participant_id' => ['Usuário inválido ou inativo.'],
            ]);
        }

        if (! $this->chatPolicy->canMessage($user, $participantUser)) {
            throw ValidationException::withMessages([
                'participant_id' => ['Sem permissão para adicionar este participante.'],
            ]);
        }

        $activeParticipant = ChatParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('user_id', $participantId)
            ->whereNull('left_at')
            ->first();

        if ($activeParticipant) {
            throw ValidationException::withMessages([
                'participant_id' => ['Este usuário já participa do grupo.'],
            ]);
        }

        DB::transaction(function () use ($conversation, $participantId): void {
            $existing = ChatParticipant::query()
                ->where('conversation_id', (int) $conversation->id)
                ->where('user_id', $participantId)
                ->first();

            if ($existing) {
                $existing->left_at = null;
                $existing->hidden_at = null;
                $existing->is_admin = false;
                $existing->joined_at = $existing->joined_at ?? now();
                $existing->last_read_at = null;
                $existing->save();
                return;
            }

            ChatParticipant::query()->create([
                'conversation_id' => (int) $conversation->id,
                'user_id' => $participantId,
                'joined_at' => now(),
                'last_read_at' => null,
                'is_admin' => false,
                'hidden_at' => null,
                'left_at' => null,
            ]);
        });

        $conversation->refresh();
        $this->chatService->loadConversationSummaryRelations($conversation);

        $this->dispatchService->dispatchChatParticipantAddedNotification(
            $conversation,
            $participantId,
            (int) $user->id
        );

        return response()->json([
            'ok' => true,
            'conversation' => $this->chatService->serializeConversationSummary($conversation, $user),
        ]);
    }
}

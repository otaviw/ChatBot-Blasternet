<?php

namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Policies\ChatPolicy;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateConversationAction
{
    public function __construct(
        private readonly ChatPolicy $chatPolicy,
        private readonly InternalChatConversationService $chatService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $sender = $this->chatService->resolveAuthenticatedUser($request);
        if (! $sender) {
            return $this->chatService->unauthenticatedResponse();
        }

        $type = trim((string) ($request->input('type') ?? 'direct'));

        if ($type === 'group') {
            return $this->handleGroupCreation($request, $sender);
        }

        return $this->handleDirectCreation($request, $sender);
    }

    private function handleDirectCreation(Request $request, User $sender): JsonResponse
    {
        $recipientId = $this->chatService->resolveRecipientId($request);
        if ($recipientId <= 0) {
            throw ValidationException::withMessages([
                'recipient_id' => ['recipient_id e obrigatório.'],
            ]);
        }

        $recipient = User::query()->find($recipientId);
        if (! $recipient instanceof User || ! $recipient->is_active) {
            throw ValidationException::withMessages([
                'recipient_id' => ['Usuário destino inválido ou inativo.'],
            ]);
        }

        if (! $this->chatPolicy->canMessage($sender, $recipient)) {
            return response()->json([
                'message' => 'Sem permissão para iniciar conversa com este usuário.',
            ], 403);
        }

        $content = trim((string) ($request->input('content') ?? $request->input('text') ?? ''));
        $now = now();

        $conversation = $this->chatService->findDirectConversation((int) $sender->id, (int) $recipient->id);
        $isNewConversation = false;

        DB::transaction(function () use (&$conversation, &$isNewConversation, $sender, $recipient, $content, $now): void {
            if (! $conversation instanceof ChatConversation) {
                $conversation = ChatConversation::query()->create([
                    'type' => 'direct',
                    'created_by' => (int) $sender->id,
                    'company_id' => $this->chatService->resolveConversationCompanyId($sender, $recipient),
                ]);
                $isNewConversation = true;
                $conversation->participants()->attach([
                    (int) $sender->id => [
                        'joined_at' => $now,
                        'last_read_at' => $now,
                        'is_admin' => false,
                        'hidden_at' => null,
                        'left_at' => null,
                    ],
                    (int) $recipient->id => [
                        'joined_at' => $now,
                        'last_read_at' => null,
                        'is_admin' => false,
                        'hidden_at' => null,
                        'left_at' => null,
                    ],
                ]);
            } else {
                $conversation->participants()->syncWithoutDetaching([
                    (int) $sender->id => [
                        'joined_at' => $now,
                        'left_at' => null,
                        'hidden_at' => null,
                    ],
                    (int) $recipient->id => [
                        'joined_at' => $now,
                        'left_at' => null,
                        'hidden_at' => null,
                    ],
                ]);
                $conversation->participants()->updateExistingPivot((int) $sender->id, [
                    'left_at' => null,
                    'hidden_at' => null,
                ]);
                $conversation->participants()->updateExistingPivot((int) $recipient->id, [
                    'left_at' => null,
                    'hidden_at' => null,
                ]);
                $this->chatService->markConversationAsRead($conversation, (int) $sender->id);
            }

            if ($content !== '') {
                ChatMessage::query()->create([
                    'conversation_id' => (int) $conversation->id,
                    'sender_id' => (int) $sender->id,
                    'type' => 'text',
                    'content' => $content,
                    'metadata' => null,
                ]);
            }
        });

        $conversation->refresh();
        $this->chatService->loadConversationSummaryRelations($conversation);

        $message = null;
        if ($content !== '') {
            $message = $conversation->lastMessage;
        }

        return response()->json([
            'ok' => true,
            'created' => $isNewConversation,
            'conversation' => $this->chatService->serializeConversationSummary($conversation, $sender),
            'message' => $message ? $this->chatService->serializeMessage($message) : null,
        ], $isNewConversation ? 201 : 200);
    }

    private function handleGroupCreation(Request $request, User $sender): JsonResponse
    {
        $rawIds = $request->input('participant_ids')
            ?? $request->input('participantIds')
            ?? $request->input('participants')
            ?? [];

        if (! is_array($rawIds)) {
            $rawIds = [];
        }

        $participantIds = array_values(array_unique(array_filter(
            array_map(fn ($v) => (int) $v, $rawIds),
            fn (int $id) => $id > 0 && $id !== (int) $sender->id
        )));

        if (count($participantIds) < 2) {
            throw ValidationException::withMessages([
                'participant_ids' => ['Selecione pelo menos 2 participantes para criar um grupo.'],
            ]);
        }

        $users = User::query()
            ->whereIn('id', $participantIds)
            ->where('is_active', true)
            ->get();

        if ($users->count() < 2) {
            throw ValidationException::withMessages([
                'participant_ids' => ['Pelo menos 2 participantes validos e ativos sao necessarios.'],
            ]);
        }

        foreach ($users as $recipient) {
            if (! $this->chatPolicy->canMessage($sender, $recipient)) {
                throw ValidationException::withMessages([
                    'participant_ids' => ["Sem permissão para conversar com {$recipient->name}."],
                ]);
            }
        }

        $content = trim((string) ($request->input('content') ?? $request->input('text') ?? ''));
        $name = trim((string) ($request->input('name') ?? $request->input('group_name') ?? ''));
        if ($name !== '') {
            $name = mb_substr($name, 0, 120);
        }

        $now = now();
        $conversation = null;

        DB::transaction(function () use (&$conversation, $sender, $users, $content, $name, $now): void {
            $senderCompanyId = (int) ($sender->company_id ?? 0);
            $companyId = $senderCompanyId > 0 ? $senderCompanyId : null;

            $conversation = ChatConversation::query()->create([
                'type' => 'group',
                'name' => $name !== '' ? $name : null,
                'created_by' => (int) $sender->id,
                'company_id' => $companyId,
            ]);

            $attachData = [
                (int) $sender->id => [
                    'joined_at' => $now,
                    'last_read_at' => $now,
                    'is_admin' => true,
                    'hidden_at' => null,
                    'left_at' => null,
                ],
            ];

            foreach ($users as $user) {
                $attachData[(int) $user->id] = [
                    'joined_at' => $now,
                    'last_read_at' => null,
                    'is_admin' => false,
                    'hidden_at' => null,
                    'left_at' => null,
                ];
            }

            $conversation->participants()->attach($attachData);

            if ($content !== '') {
                ChatMessage::query()->create([
                    'conversation_id' => (int) $conversation->id,
                    'sender_id' => (int) $sender->id,
                    'type' => 'text',
                    'content' => $content,
                    'metadata' => null,
                ]);
            }
        });

        $conversation->refresh();
        $this->chatService->loadConversationSummaryRelations($conversation);

        $message = null;
        if ($content !== '') {
            $message = $conversation->lastMessage;
        }

        return response()->json([
            'ok' => true,
            'created' => true,
            'conversation' => $this->chatService->serializeConversationSummary($conversation, $sender),
            'message' => $message ? $this->chatService->serializeMessage($message) : null,
        ], 201);
    }
}

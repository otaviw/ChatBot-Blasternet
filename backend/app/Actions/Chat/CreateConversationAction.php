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

        $recipientId = $this->chatService->resolveRecipientId($request);
        if ($recipientId <= 0) {
            throw ValidationException::withMessages([
                'recipient_id' => ['recipient_id e obrigatorio.'],
            ]);
        }

        $recipient = User::query()->find($recipientId);
        if (! $recipient instanceof User || ! $recipient->is_active) {
            throw ValidationException::withMessages([
                'recipient_id' => ['Usuario destino invalido ou inativo.'],
            ]);
        }

        if (! $this->chatPolicy->canMessage($sender, $recipient)) {
            return response()->json([
                'message' => 'Sem permissao para iniciar conversa com este usuario.',
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
                    ],
                    (int) $recipient->id => [
                        'joined_at' => $now,
                        'last_read_at' => null,
                    ],
                ]);
            } else {
                $conversation->participants()->syncWithoutDetaching([
                    (int) $sender->id,
                    (int) $recipient->id,
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
}

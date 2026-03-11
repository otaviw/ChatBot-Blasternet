<?php

namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ToggleReactionAction
{
    public function __construct(
        private readonly InternalChatConversationService $chatService
    ) {}

    public function handle(Request $request, ChatConversation $conversation, ChatMessage $message): JsonResponse
    {
        $user = $this->chatService->resolveAuthenticatedUser($request);
        if (! $user) {
            return $this->chatService->unauthenticatedResponse();
        }

        if (! $this->chatService->isParticipant($conversation, (int) $user->id)) {
            return response()->json(['message' => 'Sem permissao para reagir nesta conversa.'], 403);
        }

        if (! $this->chatService->belongsToConversation($conversation, $message)) {
            return response()->json(['message' => 'Mensagem nao pertence a conversa informada.'], 404);
        }

        if ($message->deleted_at) {
            throw ValidationException::withMessages([
                'emoji' => ['Nao e possivel reagir a uma mensagem apagada.'],
            ]);
        }

        $emoji = trim((string) $request->input('emoji', ''));
        if ($emoji === '') {
            throw ValidationException::withMessages([
                'emoji' => ['Informe um emoji valido para reagir.'],
            ]);
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $reactions = $metadata['reactions'] ?? [];
        $userId = (int) $user->id;

        $current = array_map('intval', $reactions[$emoji] ?? []);
        if (in_array($userId, $current, true)) {
            $current = array_values(array_filter($current, fn (int $id) => $id !== $userId));
        } else {
            $current[] = $userId;
        }

        if ($current === []) {
            unset($reactions[$emoji]);
        } else {
            $reactions[$emoji] = array_values(array_unique($current));
        }

        $metadata['reactions'] = $reactions;
        $message->metadata = $metadata;
        $message->save();

        $message->refresh()->load([
            'sender:id,name,email,role,company_id,is_active',
            'attachments:id,message_id,url,mime_type,size_bytes,original_name',
        ]);
        $conversation->refresh();
        $this->chatService->loadConversationSummaryRelations($conversation);

        return response()->json([
            'ok' => true,
            'conversation' => $this->chatService->serializeConversationSummary($conversation, $user),
            'message' => $this->chatService->serializeMessage($message),
        ]);
    }
}

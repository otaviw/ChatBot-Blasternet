<?php

namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UpdateMessageAction
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
            return response()->json([
                'message' => 'Sem permissao para editar mensagem nesta conversa.',
            ], 403);
        }

        if (! $this->chatService->belongsToConversation($conversation, $message)) {
            return response()->json([
                'message' => 'Mensagem nao pertence a conversa informada.',
            ], 404);
        }

        if ((int) $message->sender_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Apenas o dono da mensagem pode editar.',
            ], 403);
        }

        if ($message->deleted_at) {
            throw ValidationException::withMessages([
                'message' => ['Nao e possivel editar uma mensagem apagada.'],
            ]);
        }

        $content = trim((string) ($request->input('content') ?? $request->input('text') ?? ''));
        if ($content === '') {
            throw ValidationException::withMessages([
                'content' => ['Informe o novo texto da mensagem para editar.'],
            ]);
        }

        if ((string) ($message->content ?? '') !== $content) {
            $message->content = $content;
            $message->edited_at = now();
            $message->save();
        }

        $message->load([
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

<?php

namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteMessageAction
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
                'message' => 'Sem permissao para apagar mensagem nesta conversa.',
            ], 403);
        }

        if (! $this->chatService->belongsToConversation($conversation, $message)) {
            return response()->json([
                'message' => 'Mensagem nao pertence a conversa informada.',
            ], 404);
        }

        if ((int) $message->sender_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Apenas o dono da mensagem pode apagar.',
            ], 403);
        }

        if (! $message->deleted_at) {
            DB::transaction(function () use ($message): void {
                $paths = $message->attachments()
                    ->whereNotNull('disk_path')
                    ->pluck('disk_path')
                    ->filter(fn ($path) => trim((string) $path) !== '')
                    ->values()
                    ->all();

                if ($paths !== []) {
                    try {
                        Storage::disk('public')->delete($paths);
                    } catch (\Throwable) {
                        // Falha na limpeza fisica nao deve impedir apagamento logico.
                    }
                }

                $message->attachments()->delete();
                $metadata = is_array($message->metadata) ? $message->metadata : [];
                $metadata['deleted'] = true;
                $metadata['deleted_by_sender'] = true;

                $message->content = null;
                $message->metadata = $metadata;
                $message->deleted_at = now();
                $message->save();
            });
        }

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

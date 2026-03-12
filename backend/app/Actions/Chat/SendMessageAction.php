<?php

namespace App\Actions\Chat;

use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SendMessageAction
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
                'message' => 'Conversa nao encontrada.',
            ], 404);
        }

        if (! $this->chatService->isVisibleParticipant($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Sem permissao para enviar mensagem nesta conversa.',
            ], 403);
        }

        $uploadedFile = $request->file('file') ?? $request->file('attachment');
        $content = trim((string) ($request->input('content') ?? $request->input('text') ?? ''));

        if ($content === '' && ! $uploadedFile) {
            throw ValidationException::withMessages([
                'content' => ['Envie texto ou anexo para continuar.'],
            ]);
        }

        $requestedType = trim(mb_strtolower((string) $request->input('type', '')));
        $messageType = in_array($requestedType, ['text', 'image', 'file'], true)
            ? $requestedType
            : ($uploadedFile ? ($this->chatService->isImageFileMime((string) $uploadedFile->getMimeType()) ? 'image' : 'file') : 'text');

        $createdMessage = null;
        DB::transaction(function () use (&$createdMessage, $conversation, $user, $uploadedFile, $content, $messageType): void {
            $createdMessage = ChatMessage::query()->create([
                'conversation_id' => (int) $conversation->id,
                'sender_id' => (int) $user->id,
                'type' => $messageType,
                'content' => $content !== '' ? $content : null,
                'metadata' => null,
            ]);

            if ($uploadedFile) {
                $storedPath = $uploadedFile->store('chat', 'public');
                $publicUrl = Storage::disk('public')->url($storedPath);

                ChatAttachment::query()->create([
                    'message_id' => (int) $createdMessage->id,
                    'disk_path' => $storedPath,
                    'url' => $publicUrl,
                    'mime_type' => (string) ($uploadedFile->getMimeType() ?? 'application/octet-stream'),
                    'size_bytes' => (int) $uploadedFile->getSize(),
                    'original_name' => (string) $uploadedFile->getClientOriginalName(),
                ]);
            }

            $this->chatService->markConversationAsRead($conversation, (int) $user->id);
            $this->chatService->clearConversationHiddenForActiveParticipants($conversation);
        });

        $conversation->refresh();
        $this->chatService->loadConversationSummaryRelations($conversation);
        $createdMessage->load([
            'sender:id,name,email,role,company_id,is_active',
            'attachments:id,message_id,url,mime_type,size_bytes,original_name',
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $this->chatService->serializeConversationSummary($conversation, $user),
            'message' => $this->chatService->serializeMessage($createdMessage),
        ]);
    }
}

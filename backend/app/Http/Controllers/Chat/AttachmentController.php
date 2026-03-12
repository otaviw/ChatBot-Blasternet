<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatAttachment;
use App\Models\User;
use App\Services\Chat\InternalChatConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function __construct(
        private readonly InternalChatConversationService $chatService
    ) {}

    public function media(Request $request, ChatAttachment $attachment)
    {
        $user = $this->chatService->resolveAuthenticatedUser($request);
        if (! $user instanceof User) {
            return $this->chatService->unauthenticatedResponse();
        }

        $attachment->loadMissing('message.conversation');
        $conversation = $attachment->message?->conversation;

        if (
            ! $conversation
            || $this->chatService->isConversationDeleted($conversation)
            || ! $this->chatService->isVisibleParticipant($conversation, (int) $user->id)
        ) {
            return response()->json([
                'message' => 'Sem permissao para acessar este anexo.',
            ], 403);
        }

        $diskPath = trim((string) ($attachment->disk_path ?? ''));
        if ($diskPath === '') {
            $urlPath = parse_url((string) ($attachment->url ?? ''), PHP_URL_PATH);
            $normalizedPath = trim((string) $urlPath, '/');
            if (str_starts_with($normalizedPath, 'storage/')) {
                $normalizedPath = substr($normalizedPath, strlen('storage/'));
            }
            $diskPath = $normalizedPath;
        }

        if ($diskPath === '') {
            return response()->json([
                'message' => 'Anexo nao encontrado.',
            ], 404);
        }

        $disk = 'public';
        if (! Storage::disk($disk)->exists($diskPath)) {
            return response()->json([
                'message' => 'Arquivo do anexo nao encontrado.',
            ], 404);
        }

        $headers = [];
        if ($attachment->mime_type) {
            $headers['Content-Type'] = (string) $attachment->mime_type;
        }

        return Storage::disk($disk)->response(
            $diskPath,
            $attachment->original_name ?: null,
            $headers
        );
    }
}

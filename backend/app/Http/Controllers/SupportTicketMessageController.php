<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketMessageAttachment;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\MessageMediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupportTicketMessageController extends Controller
{
    public function __construct(
        private AuditLogService $auditLog,
        private MessageMediaStorageService $mediaStorage
    ) {}

    public function listMine(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->unauthenticatedResponse();
        }

        if ((int) ($ticket->requester_user_id ?? 0) !== (int) $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para visualizar este chat.',
            ], 403);
        }

        return $this->listMessagesResponse($ticket);
    }

    public function storeMine(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->unauthenticatedResponse();
        }

        if ((int) ($ticket->requester_user_id ?? 0) !== (int) $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para enviar mensagens neste chat.',
            ], 403);
        }

        return $this->storeMessage($request, $ticket, $user);
    }

    public function listAdmin(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isSystemAdmin()) {
            return $this->unauthenticatedResponse();
        }

        return $this->listMessagesResponse($ticket);
    }

    public function storeAdmin(Request $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isSystemAdmin()) {
            return $this->unauthenticatedResponse();
        }

        return $this->storeMessage($request, $ticket, $user);
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'authenticated' => false,
            'redirect' => '/entrar',
        ], 403);
    }

    private function listMessagesResponse(SupportTicket $ticket): JsonResponse
    {
        $messages = SupportTicketMessage::query()
            ->with(['sender:id,name,email,role,is_active', 'attachments'])
            ->where('support_ticket_id', (int) $ticket->id)
            ->orderBy('id')
            ->limit(1000)
            ->get();

        return response()->json([
            'ok' => true,
            'messages' => $messages
                ->map(fn (SupportTicketMessage $message): array => $this->serializeMessage($message))
                ->values()
                ->all(),
        ]);
    }

    private function storeMessage(Request $request, SupportTicket $ticket, User $sender): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:8000'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:' . (config('whatsapp.media_max_size_kb', 5120))],
        ]);

        $content = trim((string) ($validated['message'] ?? ''));
        $uploadedImages = $request->file('images') ?? [];
        if (! is_array($uploadedImages)) {
            $uploadedImages = [$uploadedImages];
        }

        $images = [];
        foreach ($uploadedImages as $imageFile) {
            if (! $imageFile || ! $imageFile->isValid()) {
                continue;
            }
            $images[] = $imageFile;
        }

        if ($content === '' && $images === []) {
            throw ValidationException::withMessages([
                'message' => ['Envie texto ou pelo menos uma imagem para continuar.'],
            ]);
        }

        $messageType = $images === []
            ? SupportTicketMessage::TYPE_TEXT
            : SupportTicketMessage::TYPE_IMAGE;

        $createdMessage = null;

        DB::transaction(function () use (&$createdMessage, $ticket, $sender, $content, $messageType, $images): void {
            $createdMessage = SupportTicketMessage::query()->create([
                'support_ticket_id' => (int) $ticket->id,
                'sender_user_id' => (int) $sender->id,
                'type' => $messageType,
                'content' => $content !== '' ? $content : null,
            ]);

            foreach ($images as $imageFile) {
                $stored = $this->mediaStorage->storeSupportTicketImage($imageFile);

                SupportTicketMessageAttachment::query()->create([
                    'support_ticket_message_id' => (int) $createdMessage->id,
                    'storage_provider' => (string) $stored['provider'],
                    'storage_key' => (string) $stored['key'],
                    'url' => null,
                    'mime_type' => (string) ($stored['mime_type'] ?? 'application/octet-stream'),
                    'size_bytes' => isset($stored['size_bytes']) ? (int) $stored['size_bytes'] : null,
                ]);
            }
        });

        if (! $createdMessage instanceof SupportTicketMessage) {
            return response()->json([
                'message' => 'Não foi possível enviar a mensagem.',
            ], 500);
        }

        $createdMessage->load(['sender:id,name,email,role,is_active', 'attachments']);

        $this->auditLog->record($request, 'support.ticket.message.created', $ticket->company_id, [
            'ticket_id' => (int) $ticket->id,
            'ticket_number' => (int) ($ticket->ticket_number ?: $ticket->id),
            'message_id' => (int) $createdMessage->id,
            'sender_user_id' => (int) $sender->id,
            'type' => (string) $createdMessage->type,
            'attachments_count' => (int) $createdMessage->attachments->count(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => $this->serializeMessage($createdMessage),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(SupportTicketMessage $message): array
    {
        $sender = $message->sender;

        return [
            'id' => (int) $message->id,
            'support_ticket_id' => (int) $message->support_ticket_id,
            'sender_user_id' => $message->sender_user_id ? (int) $message->sender_user_id : null,
            'sender_name' => (string) ($sender?->name ?? 'Usuário'),
            'sender_is_admin' => $sender ? (bool) $sender->isSystemAdmin() : false,
            'type' => (string) $message->type,
            'content' => (string) ($message->content ?? ''),
            'attachments' => $message->relationLoaded('attachments')
                ? $message->attachments
                    ->map(fn (SupportTicketMessageAttachment $attachment): array => $this->serializeAttachment($attachment))
                    ->values()
                    ->all()
                : [],
            'created_at' => $message->created_até->toISOString(),
            'updated_at' => $message->updated_até->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttachment(SupportTicketMessageAttachment $attachment): array
    {
        $attachmentId = (int) ($attachment->id ?? 0);
        $mediaUrl = $attachmentId > 0
            ? "/api/support/ticket-chat/attachments/{$attachmentId}/media"
            : (string) ($attachment->url ?? '');

        return [
            'id' => $attachmentId,
            'url' => $mediaUrl,
            'media_url' => $mediaUrl,
            'mime_type' => (string) ($attachment->mime_type ?? ''),
            'size_bytes' => $attachment->size_bytes ? (int) $attachment->size_bytes : null,
        ];
    }
}

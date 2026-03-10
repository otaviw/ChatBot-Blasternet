<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatAttachment;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Policies\ChatPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse();
        }

        $search = trim((string) $request->query('search', ''));
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $query = ChatConversation::query()
            ->whereHas('participants', function ($participantsQuery) use ($user): void {
                $participantsQuery->where('users.id', (int) $user->id);
            })
            ->with([
                'participants:id,name,email,role,company_id,is_active',
                'lastMessage.sender:id,name,email,role,company_id,is_active',
                'lastMessage.attachments:id,message_id,url,mime_type,size_bytes,original_name',
            ])
            ->orderByRaw("
                COALESCE(
                    (SELECT MAX(cm.created_at) FROM chat_messages cm WHERE cm.conversation_id = chat_conversations.id),
                    chat_conversations.updated_at,
                    chat_conversations.created_at
                ) DESC
            ")
            ->orderByDesc('chat_conversations.id');

        if ($search !== '') {
            $query->where(function ($scopedQuery) use ($search): void {
                $scopedQuery->whereHas('participants', function ($participantsQuery) use ($search): void {
                    $participantsQuery
                        ->where('users.name', 'like', '%'.$search.'%')
                        ->orWhere('users.email', 'like', '%'.$search.'%');
                });

                if (ctype_digit($search)) {
                    $scopedQuery->orWhere('chat_conversations.id', (int) $search);
                }
            });
        }

        $pagination = $query->paginate($perPage)->withQueryString();

        $conversations = collect($pagination->items())
            ->map(function (ChatConversation $conversation) use ($user): array {
                return $this->serializeConversationSummary($conversation, $user);
            })
            ->values()
            ->all();

        return response()->json([
            'authenticated' => true,
            'conversations' => $conversations,
            'conversations_pagination' => [
                'current_page' => (int) $pagination->currentPage(),
                'last_page' => (int) $pagination->lastPage(),
                'per_page' => (int) $pagination->perPage(),
                'total' => (int) $pagination->total(),
            ],
        ]);
    }

    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse();
        }

        if (! $this->isParticipant($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Sem permissao para acessar esta conversa.',
            ], 403);
        }

        $messagesLimit = min(max((int) $request->query('messages_limit', 120), 1), 300);
        $messages = ChatMessage::query()
            ->where('conversation_id', (int) $conversation->id)
            ->with([
                'sender:id,name,email,role,company_id,is_active',
                'attachments:id,message_id,url,mime_type,size_bytes,original_name',
            ])
            ->orderByDesc('id')
            ->limit($messagesLimit)
            ->get()
            ->reverse()
            ->values();

        $conversation->load([
            'participants:id,name,email,role,company_id,is_active',
            'lastMessage.sender:id,name,email,role,company_id,is_active',
            'lastMessage.attachments:id,message_id,url,mime_type,size_bytes,original_name',
        ]);
        $conversation->setRelation('messages', $messages);

        return response()->json([
            'authenticated' => true,
            'conversation' => $this->serializeConversationDetail($conversation, $user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $sender = $request->user();
        if (! $sender instanceof User) {
            return $this->unauthenticatedResponse();
        }

        $recipientId = $this->resolveRecipientId($request);
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

        /** @var ChatPolicy $chatPolicy */
        $chatPolicy = app(ChatPolicy::class);
        if (! $chatPolicy->canMessage($sender, $recipient)) {
            return response()->json([
                'message' => 'Sem permissao para iniciar conversa com este usuario.',
            ], 403);
        }

        $content = trim((string) ($request->input('content') ?? $request->input('text') ?? ''));
        $now = now();

        $conversation = $this->findDirectConversation((int) $sender->id, (int) $recipient->id);
        $isNewConversation = false;

        DB::transaction(function () use (&$conversation, &$isNewConversation, $sender, $recipient, $content, $now): void {
            if (! $conversation instanceof ChatConversation) {
                $conversation = ChatConversation::query()->create([
                    'type' => 'direct',
                    'created_by' => (int) $sender->id,
                    'company_id' => $this->resolveConversationCompanyId($sender, $recipient),
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
                $this->markConversationAsRead($conversation, (int) $sender->id);
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

        $conversation->refresh()->load([
            'participants:id,name,email,role,company_id,is_active',
            'lastMessage.sender:id,name,email,role,company_id,is_active',
            'lastMessage.attachments:id,message_id,url,mime_type,size_bytes,original_name',
        ]);

        $message = null;
        if ($content !== '') {
            $message = $conversation->lastMessage;
        }

        return response()->json([
            'ok' => true,
            'created' => $isNewConversation,
            'conversation' => $this->serializeConversationSummary($conversation, $sender),
            'message' => $message ? $this->serializeMessage($message) : null,
        ], $isNewConversation ? 201 : 200);
    }

    public function sendMessage(Request $request, ChatConversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse();
        }

        if (! $this->isParticipant($conversation, (int) $user->id)) {
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
            : ($uploadedFile ? ($this->isImageFileMime((string) $uploadedFile->getMimeType()) ? 'image' : 'file') : 'text');

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

            $this->markConversationAsRead($conversation, (int) $user->id);
        });

        $conversation->refresh()->load([
            'participants:id,name,email,role,company_id,is_active',
            'lastMessage.sender:id,name,email,role,company_id,is_active',
            'lastMessage.attachments:id,message_id,url,mime_type,size_bytes,original_name',
        ]);
        $createdMessage->load([
            'sender:id,name,email,role,company_id,is_active',
            'attachments:id,message_id,url,mime_type,size_bytes,original_name',
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $this->serializeConversationSummary($conversation, $user),
            'message' => $this->serializeMessage($createdMessage),
        ]);
    }

    public function updateMessage(Request $request, ChatConversation $conversation, ChatMessage $message): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse();
        }

        if (! $this->isParticipant($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Sem permissao para editar mensagem nesta conversa.',
            ], 403);
        }

        if (! $this->belongsToConversation($conversation, $message)) {
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
        $conversation->refresh()->load([
            'participants:id,name,email,role,company_id,is_active',
            'lastMessage.sender:id,name,email,role,company_id,is_active',
            'lastMessage.attachments:id,message_id,url,mime_type,size_bytes,original_name',
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $this->serializeConversationSummary($conversation, $user),
            'message' => $this->serializeMessage($message),
        ]);
    }

    public function deleteMessage(Request $request, ChatConversation $conversation, ChatMessage $message): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse();
        }

        if (! $this->isParticipant($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Sem permissao para apagar mensagem nesta conversa.',
            ], 403);
        }

        if (! $this->belongsToConversation($conversation, $message)) {
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
        $conversation->refresh()->load([
            'participants:id,name,email,role,company_id,is_active',
            'lastMessage.sender:id,name,email,role,company_id,is_active',
            'lastMessage.attachments:id,message_id,url,mime_type,size_bytes,original_name',
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $this->serializeConversationSummary($conversation, $user),
            'message' => $this->serializeMessage($message),
        ]);
    }

    public function markRead(Request $request, ChatConversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse();
        }

        if (! $this->isParticipant($conversation, (int) $user->id)) {
            return response()->json([
                'message' => 'Sem permissao para marcar leitura desta conversa.',
            ], 403);
        }

        $this->markConversationAsRead($conversation, (int) $user->id);

        return response()->json([
            'ok' => true,
            'conversation_id' => (int) $conversation->id,
            'unread_count' => 0,
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $sender = $request->user();
        if (! $sender instanceof User) {
            return $this->unauthenticatedResponse();
        }

        $search = trim((string) $request->query('search', ''));

        $query = User::query()
            ->where('is_active', true)
            ->where('id', '!=', (int) $sender->id);

        if (! $sender->isSystemAdmin()) {
            $companyId = (int) ($sender->company_id ?? 0);

            $query->where(function ($scope) use ($companyId): void {
                $scope->whereIn('role', [User::ROLE_SYSTEM_ADMIN, User::ROLE_LEGACY_ADMIN]);
                if ($companyId > 0) {
                    $scope->orWhere('company_id', $companyId);
                }
            });
        }

        if ($search !== '') {
            $query->where(function ($scope) use ($search): void {
                $scope->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        $candidates = $query
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name', 'email', 'role', 'company_id', 'is_active']);

        /** @var ChatPolicy $chatPolicy */
        $chatPolicy = app(ChatPolicy::class);
        $users = $candidates
            ->filter(function (User $candidate) use ($chatPolicy, $sender): bool {
                return $chatPolicy->canMessage($sender, $candidate);
            })
            ->map(function (User $candidate): array {
                return $this->serializeUser($candidate);
            })
            ->values()
            ->all();

        return response()->json([
            'authenticated' => true,
            'users' => $users,
        ]);
    }

    private function findDirectConversation(int $userA, int $userB): ?ChatConversation
    {
        return ChatConversation::query()
            ->where('type', 'direct')
            ->whereHas('participants', function ($participantsQuery) use ($userA): void {
                $participantsQuery->where('users.id', $userA);
            })
            ->whereHas('participants', function ($participantsQuery) use ($userB): void {
                $participantsQuery->where('users.id', $userB);
            })
            ->whereDoesntHave('participants', function ($participantsQuery) use ($userA, $userB): void {
                $participantsQuery->whereNotIn('users.id', [$userA, $userB]);
            })
            ->first();
    }

    private function resolveRecipientId(Request $request): int
    {
        $rawId = $request->input('recipient_id')
            ?? $request->input('recipientId')
            ?? $request->input('user_id')
            ?? $request->input('userId');

        return (int) $rawId;
    }

    private function resolveConversationCompanyId(User $sender, User $recipient): ?int
    {
        $senderCompanyId = (int) ($sender->company_id ?? 0);
        if ($senderCompanyId > 0) {
            return $senderCompanyId;
        }

        $recipientCompanyId = (int) ($recipient->company_id ?? 0);

        return $recipientCompanyId > 0 ? $recipientCompanyId : null;
    }

    private function markConversationAsRead(ChatConversation $conversation, int $userId): void
    {
        $timestamp = now();
        $pivot = ChatParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('user_id', $userId)
            ->first();

        if ($pivot) {
            $pivot->last_read_at = $timestamp;
            $pivot->save();
            return;
        }

        ChatParticipant::query()->create([
            'conversation_id' => (int) $conversation->id,
            'user_id' => $userId,
            'joined_at' => $timestamp,
            'last_read_at' => $timestamp,
        ]);
    }

    private function isParticipant(ChatConversation $conversation, int $userId): bool
    {
        return $conversation->participants()
            ->where('user_id', $userId)
            ->exists();
    }

    private function belongsToConversation(ChatConversation $conversation, ChatMessage $message): bool
    {
        return (int) $message->conversation_id === (int) $conversation->id;
    }

    private function calculateUnreadCount(ChatConversation $conversation, User $viewer): int
    {
        $participant = $conversation->participants
            ->first(fn (User $item): bool => (int) $item->id === (int) $viewer->id);

        if (! $participant) {
            return 0;
        }

        $lastReadAt = $participant->pivot?->last_read_at;

        $query = ChatMessage::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('sender_id', '!=', (int) $viewer->id)
            ->whereNull('deleted_at');

        if ($lastReadAt) {
            $query->where('created_at', '>', $lastReadAt);
        }

        return (int) $query->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversationSummary(ChatConversation $conversation, User $viewer): array
    {
        $lastMessage = $conversation->lastMessage;

        return [
            'id' => (int) $conversation->id,
            'type' => (string) $conversation->type,
            'created_by' => (int) $conversation->created_by,
            'company_id' => $conversation->company_id ? (int) $conversation->company_id : null,
            'participants' => $conversation->participants
                ->map(fn (User $participant): array => $this->serializeUser($participant))
                ->values()
                ->all(),
            'last_message' => $lastMessage ? $this->serializeMessage($lastMessage) : null,
            'last_message_at' => $lastMessage?->created_at?->toISOString()
                ?? $conversation->updated_at?->toISOString()
                ?? $conversation->created_at?->toISOString(),
            'unread_count' => $this->calculateUnreadCount($conversation, $viewer),
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversationDetail(ChatConversation $conversation, User $viewer): array
    {
        $summary = $this->serializeConversationSummary($conversation, $viewer);

        $summary['messages'] = $conversation->messages
            ->map(fn (ChatMessage $message): array => $this->serializeMessage($message))
            ->values()
            ->all();

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => User::normalizeRole((string) $user->role),
            'company_id' => $user->company_id ? (int) $user->company_id : null,
            'is_active' => (bool) $user->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(ChatMessage $message): array
    {
        $isDeleted = (bool) $message->deleted_at;
        $serializedAttachments = $isDeleted
            ? []
            : $message->attachments
                ->map(fn (ChatAttachment $attachment): array => $this->serializeAttachment($attachment))
                ->values()
                ->all();

        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_id' => (int) $message->sender_id,
            'sender_name' => (string) ($message->sender?->name ?? 'Usuario'),
            'type' => (string) $message->type,
            'content' => $isDeleted ? 'Mensagem apagada' : (string) ($message->content ?? ''),
            'metadata' => is_array($message->metadata) ? $message->metadata : [],
            'attachments' => $serializedAttachments,
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),
            'edited_at' => $message->edited_at?->toISOString(),
            'deleted_at' => $message->deleted_at?->toISOString(),
            'is_deleted' => $isDeleted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttachment(ChatAttachment $attachment): array
    {
        return [
            'id' => (int) $attachment->id,
            'url' => (string) $attachment->url,
            'mime_type' => (string) $attachment->mime_type,
            'size_bytes' => (int) $attachment->size_bytes,
            'original_name' => (string) $attachment->original_name,
        ];
    }

    private function isImageFileMime(string $mimeType): bool
    {
        return str_starts_with(mb_strtolower(trim($mimeType)), 'image/');
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'authenticated' => false,
            'redirect' => '/entrar',
        ], 403);
    }
}

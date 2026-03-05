<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Conversation;
use App\Models\ConversationTransfer;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\MessageMediaStorageService;
use App\Services\TransferConversationService;
use App\Services\WhatsAppSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function __construct(
        private WhatsAppSendService $whatsAppSend,
        private MessageMediaStorageService $mediaStorage,
        private AuditLogService $auditLog,
        private TransferConversationService $transferService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $companyId = (int) $user->company_id;
        $conversations = Conversation::query()
            ->where('company_id', $companyId)
            ->with(['assignedUser:id,name,email', 'currentArea:id,name'])
            ->withCount('messages')
            ->latest()
            ->limit(100)
            ->get();

        $conversations->each(fn(Conversation $conversation) => $this->normalizeConversationAssignmentRelations($conversation));

        return response()->json([
            'authenticated' => true,
            'role' => 'company',
            'conversations' => $conversations,
        ]);
    }

    public function show(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $companyId = (int) $user->company_id;
        $conversation = Conversation::query()
            ->where('company_id', $companyId)
            ->whereKey($conversationId)
            ->with([
                'messages' => fn($query) => $query->oldest(),
                'assignedUser:id,name,email',
                'currentArea:id,name',
            ])
            ->first();

        if (! $conversation) {
            return response()->json([
                'message' => 'Conversa nao encontrada para esta empresa.',
            ], 404);
        }

        $this->normalizeConversationAssignmentRelations($conversation);
        $transferHistory = $this->loadTransferHistory($conversation);

        return response()->json([
            'authenticated' => true,
            'role' => 'company',
            'conversation' => $conversation,
            'transfer_history' => $transferHistory,
            'transfer_options' => $this->transferService->transferOptions($companyId),
        ]);
    }

    public function media(Request $request, int $messageId)
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $message = Message::query()
            ->whereKey($messageId)
            ->where('content_type', 'image')
            ->whereHas('conversation', function ($query) use ($user) {
                $query->where('company_id', (int) $user->company_id);
            })
            ->first();

        if (! $message || ! $message->media_key) {
            return response()->json(['message' => 'Mídia não encontrada.'], 404);
        }

        $disk = $message->media_provider ?: (string) config('whatsapp.media_disk', 'public');
        if (! Storage::disk($disk)->exists($message->media_key)) {
            return response()->json(['message' => 'Arquivo de mídia não encontrado.'], 404);
        }

        $headers = [];
        if ($message->media_mime_type) {
            $headers['Content-Type'] = (string) $message->media_mime_type;
        }

        return Storage::disk($disk)->response($message->media_key, null, $headers);
    }

    public function assume(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        $firstArea = $user->areas()->orderBy('name')->first(['areas.id', 'areas.name']);

        $conversation->handling_mode = 'human';
        $conversation->assigned_type = 'user';
        $conversation->assigned_id = (int) $user->id;
        $conversation->current_area_id = $firstArea?->id;
        $conversation->assigned_user_id = (int) $user->id;
        $conversation->assigned_area = $firstArea?->name;
        $conversation->assumed_at = now();
        $conversation->status = 'in_progress';
        $conversation->save();

        $this->auditLog->record($request, 'company.conversation.assumed', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'assigned_type' => 'user',
            'assigned_id' => $user->id,
        ]);

        $conversation->load(['assignedUser:id,name,email', 'currentArea:id,name']);
        $this->normalizeConversationAssignmentRelations($conversation);

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function release(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        $conversation->handling_mode = 'bot';
        $conversation->assigned_type = 'bot';
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $conversation->status = 'open';
        $conversation->save();

        $this->auditLog->record($request, 'company.conversation.released', $conversation->company_id, [
            'conversation_id' => $conversation->id,
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function manualReply(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'file', 'image', 'max:'.config('whatsapp.media_max_size_kb', 5120)],
            'send_outbound' => ['sometimes', 'boolean'],
        ]);

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->with(['company', 'currentArea:id,name'])
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        if (! $conversation->isManualMode()) {
            $this->assignConversationToCurrentUser($conversation, $user);
        } elseif ($conversation->assigned_type === 'user' && (int) $conversation->assigned_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Conversa assumida por outro operador.',
            ], 409);
        } elseif ($conversation->assigned_type === 'area' && ! $user->hasArea((int) ($conversation->assigned_id ?? 0))) {
            return response()->json([
                'message' => 'Conversa destinada para outra área de atendimento.',
            ], 409);
        } elseif (in_array($conversation->assigned_type, ['bot', 'unassigned'], true)) {
            $this->assignConversationToCurrentUser($conversation, $user);
        }

        $conversation->status = 'in_progress';
        $conversation->save();

        $trimmedText = trim((string) ($validated['text'] ?? ''));
        $uploadedImage = $request->file('image');
        if ($trimmedText === '' && ! $uploadedImage) {
            return response()->json([
                'message' => 'Informe texto ou imagem para enviar.',
            ], 422);
        }

        $storedMedia = null;
        if ($uploadedImage) {
            $storedMedia = $this->mediaStorage->storeUploadedImage($uploadedImage, $conversation->company_id);
        }

        $contentType = $storedMedia ? 'image' : 'text';

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'human',
            'content_type' => $contentType,
            'text' => $trimmedText !== '' ? $trimmedText : null,
            'media_provider' => $storedMedia['provider'] ?? null,
            'media_key' => $storedMedia['key'] ?? null,
            'media_url' => $storedMedia['url'] ?? null,
            'media_mime_type' => $storedMedia['mime_type'] ?? null,
            'media_size_bytes' => $storedMedia['size_bytes'] ?? null,
            'media_width' => $storedMedia['width'] ?? null,
            'media_height' => $storedMedia['height'] ?? null,
            'meta' => [
                'source' => 'manual',
                'actor_user_id' => $user->id,
            ],
        ]);

        $sendOutbound = (bool) ($validated['send_outbound'] ?? true);
        $wasSent = false;
        if ($sendOutbound) {
            if ($contentType === 'image') {
                $wasSent = $this->whatsAppSend->sendImage(
                    $conversation->company,
                    $conversation->customer_phone,
                    (string) ($message->media_url ?? ''),
                    $message->text
                );
            } else {
                $wasSent = $this->whatsAppSend->sendText(
                    $conversation->company,
                    $conversation->customer_phone,
                    (string) $message->text
                );
            }
        }

        $this->auditLog->record($request, 'company.conversation.manual_reply', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'sent' => $wasSent,
        ]);

        $conversation->load(['assignedUser:id,name,email', 'currentArea:id,name']);
        $this->normalizeConversationAssignmentRelations($conversation);

        return response()->json([
            'ok' => true,
            'message' => $message,
            'was_sent' => $wasSent,
            'conversation' => $conversation,
        ]);
    }

    public function close(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        $conversation->status = 'closed';
        $conversation->handling_mode = 'bot';
        $conversation->assigned_type = 'unassigned';
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $conversation->closed_at = now();
        $conversation->save();

        $this->auditLog->record($request, 'company.conversation.closed', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'closed_by' => $user->id,
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function updateTags(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'tags' => ['present', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        $tags = collect($validated['tags'])
            ->map(fn($tag) => strtolower(trim((string) $tag)))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $conversation->tags = $tags;
        $conversation->save();

        $this->auditLog->record($request, 'company.conversation.tags_updated', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'tags' => $tags,
        ]);

        return response()->json([
            'ok' => true,
            'tags' => $tags,
        ]);
    }

    public function updateContact(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:160'],
        ]);

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        $customerName = trim((string) ($validated['customer_name'] ?? ''));
        $customerName = $customerName !== '' ? $customerName : null;
        $before = $conversation->customer_name;

        $conversation->customer_name = $customerName;
        $conversation->save();

        $this->auditLog->record($request, 'company.conversation.contact_updated', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'before_customer_name' => $before,
            'after_customer_name' => $conversation->customer_name,
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function transfer(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:user,area'],
            'id' => ['nullable', 'integer', 'min:1'],
            'to_user_id' => ['nullable', 'integer', 'min:1'],
            'to_area' => ['nullable', 'string', 'max:120'],
            'send_outbound' => ['sometimes', 'boolean'],
        ]);

        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        [$targetType, $targetId] = $this->resolveTransferTargetFromLegacyPayload(
            (int) $user->company_id,
            $validated
        );

        try {
            $result = $this->transferService->transfer(
                $conversation,
                $user,
                $targetType,
                $targetId,
                (bool) ($validated['send_outbound'] ?? true)
            );
        } catch (ValidationException $exception) {
            $messages = $exception->errors();

            return response()->json([
                'message' => collect($messages)->flatten()->first() ?: 'Transferencia invalida.',
                'errors' => $messages,
            ], 422);
        }

        $transfer = $result['transfer'];
        $this->auditLog->record($request, 'company.conversation.transferred', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'from_assigned_type' => $transfer->from_assigned_type,
            'from_assigned_id' => $transfer->from_assigned_id,
            'to_assigned_type' => $transfer->to_assigned_type,
            'to_assigned_id' => $transfer->to_assigned_id,
            'auto_accepted' => true,
        ]);

        $updatedConversation = Conversation::query()
            ->whereKey($conversation->id)
            ->with(['assignedUser:id,name,email', 'currentArea:id,name'])
            ->firstOrFail();

        $this->normalizeConversationAssignmentRelations($updatedConversation);
        $history = $this->loadTransferHistory($updatedConversation);

        return response()->json([
            'ok' => true,
            'was_sent' => $result['was_sent'],
            'message' => $result['message'],
            'system_message' => $result['message'],
            'transfer' => $transfer,
            'conversation' => $updatedConversation,
            'transfer_history' => $history,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveTransferTargetFromLegacyPayload(int $companyId, array $validated): array
    {
        $type = $validated['type'] ?? null;
        $id = $validated['id'] ?? null;

        if ($type && $id) {
            return [(string) $type, (int) $id];
        }

        if (! empty($validated['to_user_id'])) {
            return ['user', (int) $validated['to_user_id']];
        }

        $toArea = trim((string) ($validated['to_area'] ?? ''));
        if ($toArea !== '') {
            $area = Area::query()
                ->where('company_id', $companyId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($toArea)])
                ->first();

            if (! $area) {
                throw ValidationException::withMessages([
                    'to_area' => ['Área destino não encontrada para esta empresa.'],
                ]);
            }

            return ['area', (int) $area->id];
        }

        throw ValidationException::withMessages([
            'type' => ['Informe destino de transferencia (user ou area).'],
        ]);
    }

    private function assignConversationToCurrentUser(Conversation $conversation, User $user): void
    {
        $firstArea = $user->areas()->orderBy('name')->first(['areas.id', 'areas.name']);

        $conversation->handling_mode = 'human';
        $conversation->assigned_type = 'user';
        $conversation->assigned_id = (int) $user->id;
        $conversation->current_area_id = $firstArea?->id;
        $conversation->assigned_user_id = (int) $user->id;
        $conversation->assigned_area = $firstArea?->name;
        $conversation->assumed_at = now();
    }

    private function normalizeConversationAssignmentRelations(Conversation $conversation): void
    {
        if ($conversation->assigned_type !== 'user') {
            $conversation->setRelation('assignedUser', null);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadTransferHistory(Conversation $conversation): array
    {
        $history = ConversationTransfer::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->get();

        if ($history->isEmpty()) {
            return [];
        }

        $userIds = $history
            ->flatMap(fn(ConversationTransfer $item) => [
                $item->transferred_by_user_id,
                $item->from_assigned_type === 'user' ? $item->from_assigned_id : null,
                $item->to_assigned_type === 'user' ? $item->to_assigned_id : null,
            ])
            ->filter()
            ->unique()
            ->values();

        $areaIds = $history
            ->flatMap(fn(ConversationTransfer $item) => [
                $item->from_assigned_type === 'area' ? $item->from_assigned_id : null,
                $item->to_assigned_type === 'area' ? $item->to_assigned_id : null,
            ])
            ->filter()
            ->unique()
            ->values();

        $usersById = User::query()
            ->whereIn('id', $userIds->all())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $areasById = Area::query()
            ->whereIn('id', $areaIds->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        return $history->map(function (ConversationTransfer $item) use ($usersById, $areasById) {
            $fromUser = $item->from_assigned_type === 'user'
                ? $usersById->get($item->from_assigned_id)
                : null;
            $toUser = $item->to_assigned_type === 'user'
                ? $usersById->get($item->to_assigned_id)
                : null;
            $transferredBy = $usersById->get($item->transferred_by_user_id);

            return [
                'id' => $item->id,
                'from_assigned_type' => $item->from_assigned_type,
                'from_assigned_id' => $item->from_assigned_id,
                'to_assigned_type' => $item->to_assigned_type,
                'to_assigned_id' => $item->to_assigned_id,
                'from_user' => $fromUser,
                'to_user' => $toUser,
                'from_area' => $item->from_assigned_type === 'area'
                    ? ($areasById->get($item->from_assigned_id)?->name)
                    : null,
                'to_area' => $item->to_assigned_type === 'area'
                    ? ($areasById->get($item->to_assigned_id)?->name)
                    : null,
                'transferred_by_user' => $transferredBy,
                'created_at' => $item->created_at,
            ];
        })->values()->all();
    }
}

<?php

declare(strict_types=1);


namespace App\Http\Controllers\Admin;

use App\Actions\Conversation\SearchConversationsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SearchConversationsRequest;
use App\Http\Requests\Admin\UpdateConversationContactRequest;
use App\Models\Company;
use App\Models\Conversation;
use App\Services\AuditLogService;
use App\Support\AdminPrivacySanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actorResellerId = $this->resolveActorResellerId($request);

        $query = Conversation::query()
            ->with(['company:id,name'])
            ->withCount('messages')
            ->withCount('tags')
            ->latest();

        $this->applyActorScopeToConversationQuery($query, $actorResellerId);

        $companyId = $request->query('company_id');
        if ($companyId) {
            $query->where('company_id', (int) $companyId);
        }

        $perPage = min(max((int) $request->query('per_page', 150), 1), 200);
        $page = max(1, (int) $request->query('page', 1));

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $conversations = $paginator->getCollection();
        $sanitized = AdminPrivacySanitizer::conversationSummaryCollection($conversations);
        $byStatus = $conversations
            ->groupBy(fn (Conversation $conversation) => (string) $conversation->status)
            ->map(fn ($items) => count($items))
            ->all();

        return response()->json([
            'authenticated' => true,
            'role' => 'admin',
            'privacy_mode' => 'blind_default',
            'conversations' => $sanitized,
            'metrics' => [
                'total' => count($sanitized),
                'by_status' => $byStatus,
            ],
            'pagination' => [
                'current_page' => (int) $paginator->currentPage(),
                'last_page' => (int) $paginator->lastPage(),
                'per_page' => (int) $paginator->perPage(),
                'total' => (int) $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, int $conversationId): JsonResponse
    {
        $actorResellerId = $this->resolveActorResellerId($request);

        $conversation = Conversation::query()
            ->with(['company:id,name'])
            ->withCount('messages')
            ->withCount('tags')
            ->find($conversationId);

        if (! $conversation) {
            return response()->json([
                'message' => 'Conversa nao encontrada.',
            ], 404);
        }

        if ($actorResellerId !== null && ! $this->conversationBelongsToReseller($conversation, $actorResellerId)) {
            return response()->json([
                'message' => 'Acesso negado para esta conversa.',
            ], 403);
        }

        return response()->json([
            'authenticated' => true,
            'role' => 'admin',
            'privacy_mode' => 'blind_default',
            'conversation' => AdminPrivacySanitizer::conversationSummary($conversation),
        ]);
    }

    public function search(SearchConversationsRequest $request, SearchConversationsAction $action): JsonResponse
    {
        $validated = $request->validated();
        $actorResellerId = $this->resolveActorResellerId($request);
        $companyId = (int) $validated['empresa_id'];

        if ($actorResellerId !== null && ! $this->companyBelongsToReseller($companyId, $actorResellerId)) {
            return response()->json([
                'message' => 'Acesso negado para esta empresa.',
            ], 403);
        }

        return response()->json($action->handleForAdmin($companyId, $validated));
    }

    public function assume(Request $request, int $conversationId): JsonResponse
    {
        return $this->blockedByPrivacyMode($request, 'admin.conversation.assume_blocked', $conversationId);
    }

    public function release(Request $request, int $conversationId): JsonResponse
    {
        return $this->blockedByPrivacyMode($request, 'admin.conversation.release_blocked', $conversationId);
    }

    public function manualReply(Request $request, int $conversationId): JsonResponse
    {
        return $this->blockedByPrivacyMode($request, 'admin.conversation.manual_reply_blocked', $conversationId);
    }

    public function close(Request $request, int $conversationId): JsonResponse
    {
        return $this->blockedByPrivacyMode($request, 'admin.conversation.close_blocked', $conversationId);
    }

    public function updateTags(Request $request, int $conversationId): JsonResponse
    {
        return $this->blockedByPrivacyMode($request, 'admin.conversation.tags_update_blocked', $conversationId);
    }

    public function updateContact(UpdateConversationContactRequest $request, int $conversationId): JsonResponse
    {
        $validated = $request->validated();
        $actorResellerId = $this->resolveActorResellerId($request);

        $conversation = Conversation::query()
            ->with(['company:id,name'])
            ->withCount('messages')
            ->withCount('tags')
            ->find($conversationId);

        if (! $conversation) {
            return response()->json([
                'message' => 'Conversa nao encontrada.',
            ], 404);
        }

        if ($actorResellerId !== null && ! $this->conversationBelongsToReseller($conversation, $actorResellerId)) {
            return response()->json([
                'message' => 'Acesso negado para esta conversa.',
            ], 403);
        }

        $customerName = trim((string) ($validated['customer_name'] ?? ''));
        $customerName = $customerName !== '' ? $customerName : null;
        $before = $conversation->customer_name;
        $conversation->customer_name = $customerName;
        $conversation->save();

        $this->auditLog->record($request, 'admin.conversation.contact_updated', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'before_customer_name' => $before,
            'after_customer_name' => $conversation->customer_name,
        ]);

        return response()->json([
            'ok' => true,
            'privacy_mode' => 'blind_default',
            'conversation' => AdminPrivacySanitizer::conversationSummary($conversation),
        ]);
    }

    private function blockedByPrivacyMode(Request $request, string $action, int $conversationId): JsonResponse
    {
        $conversation = Conversation::query()
            ->select(['id', 'company_id'])
            ->find($conversationId);

        $this->auditLog->record(
            $request,
            $action,
            $conversation?->company_id,
            [
                'conversation_id' => $conversationId,
            ]
        );

        return response()->json([
            'message' => 'Operacao bloqueada para superadmin no modo privacidade.',
            'privacy_mode' => 'blind_default',
        ], 403);
    }

    private function resolveActorResellerId(Request $request): ?int
    {
        $actor = $request->user();
        if (! $actor || $actor->isSystemAdmin()) {
            return null;
        }

        $resellerId = (int) ($actor->reseller_id ?? $actor->company?->reseller_id ?? 0);

        return $resellerId > 0 ? $resellerId : -1;
    }

    private function applyActorScopeToConversationQuery($query, ?int $actorResellerId): void
    {
        if ($actorResellerId === null) {
            return;
        }

        $query->whereHas('company', fn ($companyQuery) => $companyQuery->where('reseller_id', $actorResellerId));
    }

    private function conversationBelongsToReseller(Conversation $conversation, int $actorResellerId): bool
    {
        return $this->companyBelongsToReseller((int) $conversation->company_id, $actorResellerId);
    }

    private function companyBelongsToReseller(int $companyId, int $actorResellerId): bool
    {
        if ($companyId <= 0 || $actorResellerId <= 0) {
            return false;
        }

        return Company::query()
            ->where('id', $companyId)
            ->where('reseller_id', $actorResellerId)
            ->exists();
    }
}

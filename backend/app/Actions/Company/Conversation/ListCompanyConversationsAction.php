<?php

declare(strict_types=1);


namespace App\Actions\Company\Conversation;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use App\Services\Company\CompanyConversationSupportService;
use App\Services\ConversationInactivityService;
use App\Support\CacheKeys;
use App\Support\ConversationStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ListCompanyConversationsAction
{
    public function __construct(
        private readonly ConversationInactivityService $conversationInactivityService,
        private readonly CompanyConversationSupportService $conversationSupport,
        private readonly AiAccessService $aiAccessService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user, Request $request): array
    {
        $companyId = (int) $user->company_id;
        Cache::remember("company_{$companyId}_inactivity_checked", now()->addMinutes(5), function () use ($companyId) {
            $this->conversationInactivityService->closeInactiveConversations($companyId);

            return true;
        });
        $settings = $this->aiAccessService->resolveCompanySettings($user);
        $canUseInternalAi = $this->aiAccessService->canUseInternalAi($user, $settings);

        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(5, (int) $request->query('per_page', 15)));

        // Filtros adicionais
        $filterStatus = trim((string) $request->query('status', ''));
        $filterArea = trim((string) $request->query('area', ''));
        $filterAttendantId = (int) $request->query('attendant_id', 0);
        $filterTagId = (int) $request->query('tag_id', 0);
        $filterDateFrom = trim((string) $request->query('date_from', ''));
        $filterDateTo = trim((string) $request->query('date_to', ''));

        $lastMessageIdSubquery = Message::query()
            ->select('id')
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->latest('id')
            ->limit(1);

        $lastMessageAtSubquery = Message::query()
            ->select('created_at')
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->latest('id')
            ->limit(1);

        $lastMessageTextSubquery = Message::query()
            ->select('text')
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->latest('id')
            ->limit(1);

        $lastMessageDirectionSubquery = Message::query()
            ->select('direction')
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->latest('id')
            ->limit(1);

        $query = Conversation::query()
            ->where('company_id', $companyId)
            ->addSelect([
                'last_message_id' => $lastMessageIdSubquery,
                'last_message_at' => $lastMessageAtSubquery,
                'last_message_text' => $lastMessageTextSubquery,
                'last_message_direction' => $lastMessageDirectionSubquery,
            ])
            ->with([
                'assignedUser:id,name,email',
                'currentArea:id,name',
                'tags' => fn ($q) => $q->select('tags.id', 'tags.name', 'tags.color')->orderBy('tags.name'),
            ])
            ->withCount('messages')
            // PostgreSQL não permite usar alias de SELECT dentro de expressão no ORDER BY
            // (ex.: COALESCE(last_message_at, ...)). Ordenamos em duas etapas para manter
            // o mesmo resultado: conversas com última mensagem primeiro, depois sem mensagem.
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('conversations.created_at')
            ->orderByDesc('conversations.id');
        $this->conversationSupport->applyInboxVisibilityScope($query, $user);

        if ($search !== '') {
            $term = '%' . preg_replace('/\s+/', '%', $search) . '%';
            $query->where(function ($scopedQuery) use ($term) {
                $scopedQuery->where('conversations.customer_phone', 'like', $term)
                    ->orWhere('conversations.customer_name', 'like', $term);
            });
        }

        if ($filterStatus !== '' && in_array($filterStatus, ConversationStatus::all(), true)) {
            $query->where('conversations.status', $filterStatus);
        }

        if ($filterArea !== '') {
            // Comparação direta usa o índice UNIQUE(company_id, name).
            // utf8mb4_unicode_ci é case-insensitive por padrão — LOWER() era desnecessário e impedia o índice.
            $query->whereHas('currentArea', function ($q) use ($filterArea) {
                $q->where('areas.name', $filterArea);
            });
        }

        if ($filterAttendantId > 0) {
            $query->where('conversations.assigned_user_id', $filterAttendantId);
        }

        if ($filterTagId > 0) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $filterTagId));
        }

        if ($filterDateFrom !== '') {
            $query->whereDate('conversations.created_at', '>=', $filterDateFrom);
        }

        if ($filterDateTo !== '') {
            $query->whereDate('conversations.created_at', '<=', $filterDateTo);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $paginator->getCollection()->each(
            fn (Conversation $conversation) => $this->conversationSupport->normalizeConversationAssignmentRelations($conversation)
        );

        $attendants = Cache::remember("company_{$companyId}_attendants", now()->addMinutes(5), fn () => User::query()
            ->where('company_id', $companyId)
            ->whereIn('role', [User::ROLE_COMPANY_ADMIN, User::ROLE_AGENT])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray()
        );

        // Tags mudam raramente (admin cria/edita/exclui). Cache de 10 min evita a
        // query repetida em cada carregamento do inbox. Invalidado explicitamente em
        // ConversationTagController::store/update/destroy.
        $companyTags = Cache::remember(
            CacheKeys::companyTags($companyId),
            now()->addMinutes(10),
            fn () => Tag::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'name', 'color'])
                ->toArray(),
        );

        return [
            'authenticated' => true,
            'role' => 'company',
            'can_use_ai' => $canUseInternalAi,
            'can_access_internal_ai_chat' => $canUseInternalAi,
            'conversations' => $paginator->items(),
            'company_tags' => $companyTags,
            'conversations_pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'attendants' => $attendants,
        ];
    }
}

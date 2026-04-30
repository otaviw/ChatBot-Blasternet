<?php

declare(strict_types=1);


namespace App\Actions\Company\Ai;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\InternalAiConversationService;
use Illuminate\Http\Request;

class ListCompanyAiConversationsAction
{
    public function __construct(
        private readonly InternalAiConversationService $conversationService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user, Request $request): array
    {
        $companyId = $user->isSystemAdmin() ? ((int) $request->query('company_id', 0) ?: null) : null;
        $this->conversationService->ensureInternalChatEnabled($user, $companyId);

        $search = trim((string) $request->query('search', ''));
        $perPage = min(50, max(5, (int) $request->query('per_page', 15)));

        $query = $this->conversationService->queryForUser($user, $companyId)
            ->with('lastMessage')
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->orderByDesc('id');

        if ($search !== '') {
            $term = '%'.(string) preg_replace('/\s+/', '%', $search).'%';

            $query->where(function ($scopedQuery) use ($term, $search): void {
                $scopedQuery->where('title', 'like', $term);

                if (ctype_digit($search)) {
                    $scopedQuery->orWhere('id', (int) $search);
                }
            });
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        $conversations = collect($paginator->items())
            ->map(fn (AiConversation $conversation): array => $this->conversationService->serializeConversationSummary($conversation))
            ->values()
            ->all();

        return [
            'authenticated' => true,
            'role' => 'company',
            'conversations' => $conversations,
            'conversations_pagination' => $this->conversationService->paginationPayload($paginator),
        ];
    }
}

<?php

namespace App\Actions\Company\Conversation;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Company\CompanyConversationSupportService;
use App\Services\ConversationInactivityService;
use Illuminate\Http\Request;

class ListCompanyConversationsAction
{
    public function __construct(
        private readonly ConversationInactivityService $conversationInactivityService,
        private readonly CompanyConversationSupportService $conversationSupport
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user, Request $request): array
    {
        $companyId = (int) $user->company_id;
        $this->conversationInactivityService->closeInactiveConversations($companyId);

        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(5, (int) $request->query('per_page', 15)));

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

        $query = Conversation::query()
            ->where('company_id', $companyId)
            ->addSelect([
                'last_message_id' => $lastMessageIdSubquery,
                'last_message_at' => $lastMessageAtSubquery,
            ])
            ->with(['assignedUser:id,name,email', 'currentArea:id,name'])
            ->withCount('messages')
            ->orderByRaw("COALESCE(({$lastMessageAtSubquery->toSql()}), conversations.created_at) DESC")
            ->addBinding($lastMessageAtSubquery->getBindings(), 'order')
            ->orderByDesc('conversations.id');
        $this->conversationSupport->applyInboxVisibilityScope($query, $user);

        if ($search !== '') {
            $term = '%' . preg_replace('/\s+/', '%', $search) . '%';
            $query->where(function ($scopedQuery) use ($term) {
                $scopedQuery->where('conversations.customer_phone', 'like', $term)
                    ->orWhere('conversations.customer_name', 'like', $term);
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $paginator->getCollection()->each(
            fn (Conversation $conversation) => $this->conversationSupport->normalizeConversationAssignmentRelations($conversation)
        );

        return [
            'authenticated' => true,
            'role' => 'company',
            'conversations' => $paginator->items(),
            'conversations_pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}

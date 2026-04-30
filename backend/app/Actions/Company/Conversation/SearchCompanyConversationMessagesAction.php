<?php

declare(strict_types=1);


namespace App\Actions\Company\Conversation;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Company\CompanyConversationSupportService;
use Illuminate\Support\Facades\Schema;

class SearchCompanyConversationMessagesAction
{
    public function __construct(
        private readonly CompanyConversationSupportService $conversationSupport
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(User $user, int $conversationId, string $queryText, int $messagesPerPage = 25): ?array
    {
        $conversationQuery = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId);

        $this->conversationSupport->applyInboxVisibilityScope($conversationQuery, $user);
        $conversation = $conversationQuery->first();
        if (! $conversation) {
            return null;
        }

        $search = trim($queryText);
        if ($search === '') {
            return [
                'query' => '',
                'total' => 0,
                'results' => [],
            ];
        }

        $contentColumn = $this->messageContentColumn();
        $term = '%' . preg_replace('/\s+/u', '%', $search) . '%';

        $positionSubquery = Message::query()
            ->selectRaw('COUNT(*)')
            ->whereColumn('messages.conversation_id', 'm2.conversation_id')
            ->whereColumn('messages.id', '<=', 'm2.id');

        $matches = Message::query()
            ->from('messages as m2')
            ->where('m2.conversation_id', $conversation->id)
            ->where("m2.{$contentColumn}", 'like', $term)
            ->orderByDesc('m2.id')
            ->limit(50)
            ->select([
                'm2.id',
                'm2.direction',
                'm2.created_at',
            ])
            ->selectRaw("m2.{$contentColumn} as matched_text")
            ->selectSub($positionSubquery, 'message_position')
            ->get();

        $results = $matches->map(function ($message) use ($messagesPerPage, $search) {
            $position = max(1, (int) ($message->message_position ?? 1));
            $page = (int) ceil($position / max(1, $messagesPerPage));

            return [
                'message_id' => (int) $message->id,
                'direction' => (string) $message->direction,
                'created_at' => $message->created_at,
                'message_page' => $page,
                'snippet' => $this->buildSnippet((string) ($message->matched_text ?? ''), $search),
            ];
        })->values()->all();

        return [
            'query' => $search,
            'total' => count($results),
            'results' => $results,
        ];
    }

    private function buildSnippet(string $messageText, string $queryText): string
    {
        $normalizedText = trim((string) preg_replace('/\s+/u', ' ', $messageText));
        if ($normalizedText === '') {
            return '';
        }

        $position = mb_stripos($normalizedText, $queryText);
        if ($position === false) {
            return mb_strlen($normalizedText) > 120
                ? mb_substr($normalizedText, 0, 120) . '...'
                : $normalizedText;
        }

        $context = 48;
        $start = max(0, $position - $context);
        $length = mb_strlen($queryText) + ($context * 2);
        $slice = mb_substr($normalizedText, $start, $length);

        if ($start > 0) {
            $slice = '...' . $slice;
        }

        if (($start + $length) < mb_strlen($normalizedText)) {
            $slice .= '...';
        }

        return $slice;
    }

    private function messageContentColumn(): string
    {
        if (Schema::hasColumn('messages', 'body')) {
            return 'body';
        }

        return 'text';
    }
}

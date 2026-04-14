<?php

namespace App\Actions\Conversation;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Company\CompanyConversationSupportService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SearchConversationsAction
{
    public function __construct(
        private readonly CompanyConversationSupportService $conversationSupport
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function handleForCompanyUser(User $user, array $filters): array
    {
        return $this->search(
            (int) $user->company_id,
            $filters,
            $user
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function handleForAdmin(int $companyId, array $filters): array
    {
        return $this->search($companyId, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function search(int $companyId, array $filters, ?User $visibilityUser = null): array
    {
        $queryText = trim((string) ($filters['q'] ?? ''));
        if ($queryText === '') {
            return [
                'query' => '',
                'total' => 0,
                'results' => [],
            ];
        }

        $status = trim((string) ($filters['status'] ?? ''));
        $startDate = trim((string) ($filters['data_inicio'] ?? ''));
        $endDate = trim((string) ($filters['data_fim'] ?? ''));
        $term = '%' . preg_replace('/\s+/u', '%', $queryText) . '%';
        $messageContentColumn = $this->messageContentColumn();

        $actionMatchesByConversation = $this->findActionMatchesByConversation($companyId, $queryText);
        $actionConversationIds = array_map('intval', array_keys($actionMatchesByConversation));

        $matchesMessageScope = Message::query()
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->where("messages.{$messageContentColumn}", 'like', $term);

        $matchedMessageTextSubquery = Message::query()
            ->select($messageContentColumn)
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->where("messages.{$messageContentColumn}", 'like', $term)
            ->orderByDesc('messages.created_at')
            ->orderByDesc('messages.id')
            ->limit(1);

        $matchedMessageAtSubquery = Message::query()
            ->select('created_at')
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->where("messages.{$messageContentColumn}", 'like', $term)
            ->orderByDesc('messages.created_at')
            ->orderByDesc('messages.id')
            ->limit(1);

        $latestMessageTextSubquery = Message::query()
            ->select($messageContentColumn)
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->orderByDesc('messages.created_at')
            ->orderByDesc('messages.id')
            ->limit(1);

        $query = Conversation::query()
            ->select([
                'conversations.id',
                'conversations.company_id',
                'conversations.customer_phone',
                'conversations.customer_name',
                'conversations.status',
                'conversations.created_at',
            ])
            ->where('conversations.company_id', $companyId)
            ->addSelect([
                'matched_message_text' => $matchedMessageTextSubquery,
                'matched_message_at' => $matchedMessageAtSubquery,
                'latest_message_text' => $latestMessageTextSubquery,
            ])
            ->where(function ($scope) use ($term, $matchesMessageScope, $actionConversationIds) {
                $scope->where('conversations.customer_phone', 'like', $term)
                    ->orWhereExists($matchesMessageScope);

                if ($actionConversationIds !== []) {
                    $scope->orWhereIn('conversations.id', $actionConversationIds);
                }
            });

        if ($visibilityUser) {
            $this->conversationSupport->applyInboxVisibilityScope($query, $visibilityUser);
        }

        if ($status !== '') {
            $query->where('conversations.status', $status);
        }

        if ($startDate !== '') {
            $query->whereDate('conversations.created_at', '>=', $startDate);
        }

        if ($endDate !== '') {
            $query->whereDate('conversations.created_at', '<=', $endDate);
        }

        $results = $query
            ->orderByDesc('conversations.created_at')
            ->limit(150)
            ->get();

        $rankedResults = $results
            ->map(function (Conversation $conversation) use ($actionMatchesByConversation, $queryText): array {
                $conversationId = (int) $conversation->id;
                $actionMatch = $actionMatchesByConversation[$conversationId] ?? null;
                $matchedMessageText = (string) ($conversation->getAttribute('matched_message_text') ?? '');
                $latestMessageText = (string) ($conversation->getAttribute('latest_message_text') ?? '');
                $snippetSourceText = $matchedMessageText !== ''
                    ? $matchedMessageText
                    : ($actionMatch['label'] ?? $latestMessageText);
                $snippet = $this->buildSnippet((string) $snippetSourceText, $queryText);
                $matchedAt = $conversation->getAttribute('matched_message_at')
                    ?: ($actionMatch['created_at'] ?? $conversation->created_at);
                $relevance = $this->resolveRelevance(
                    (string) $conversation->customer_phone,
                    $queryText,
                    $matchedMessageText !== '',
                    $actionMatch !== null
                );

                return [
                    'conversation' => $conversation,
                    'snippet' => $snippet,
                    'matched_at' => $matchedAt,
                    'relevance' => $relevance,
                ];
            })
            ->sort(function (array $left, array $right): int {
                if ($left['relevance'] !== $right['relevance']) {
                    return $left['relevance'] <=> $right['relevance'];
                }

                $leftMatchedAt = $left['matched_at'] instanceof CarbonInterface
                    ? $left['matched_at']->getTimestamp()
                    : strtotime((string) $left['matched_at']);
                $rightMatchedAt = $right['matched_at'] instanceof CarbonInterface
                    ? $right['matched_at']->getTimestamp()
                    : strtotime((string) $right['matched_at']);

                if ($leftMatchedAt !== $rightMatchedAt) {
                    return $rightMatchedAt <=> $leftMatchedAt;
                }

                return ((int) $right['conversation']->id) <=> ((int) $left['conversation']->id);
            })
            ->take(50)
            ->values();

        return [
            'query' => $queryText,
            'total' => $rankedResults->count(),
            'results' => $this->formatResults($rankedResults),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function formatResults(Collection $results): array
    {
        return $results
            ->map(function (array $item): array {
                /** @var Conversation $conversation */
                $conversation = $item['conversation'];
                return [
                    'id' => (int) $conversation->id,
                    'customer_phone' => (string) $conversation->customer_phone,
                    'customer_name' => $conversation->customer_name,
                    'status' => (string) $conversation->status,
                    'matched_at' => $item['matched_at'],
                    'snippet' => (string) $item['snippet'],
                ];
            })
            ->values()
            ->all();
    }

    private function buildSnippet(string $messageText, string $queryText): string
    {
        $normalizedText = trim((string) preg_replace('/\s+/u', ' ', $messageText));
        if ($normalizedText === '') {
            return '';
        }

        $snippetSize = 120;
        $position = mb_stripos($normalizedText, $queryText);
        if ($position === false) {
            return mb_strlen($normalizedText) <= $snippetSize
                ? $normalizedText
                : mb_substr($normalizedText, 0, $snippetSize) . '...';
        }

        $context = 40;
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

    /**
     * @return array<int, array{label: string, created_at: mixed}>
     */
    private function findActionMatchesByConversation(int $companyId, string $queryText): array
    {
        $needle = mb_strtolower(trim($queryText));
        if ($needle === '') {
            return [];
        }

        $actions = $this->conversationActionLabels();
        $query = AuditLog::query()
            ->where('company_id', $companyId)
            ->whereNotNull('changes');

        $query->where(function ($scope) use ($actions, $needle) {
            $scope->where('action', 'like', '%' . $needle . '%');

            $matchingActionCodes = collect($actions)
                ->filter(fn (string $label, string $action) => Str::contains(
                    mb_strtolower($label . ' ' . $action),
                    $needle
                ))
                ->keys()
                ->values()
                ->all();

            if ($matchingActionCodes !== []) {
                $scope->orWhereIn('action', $matchingActionCodes);
            }
        });

        $logs = $query
            ->orderByDesc('id')
            ->limit(2000)
            ->get(['action', 'changes', 'created_at']);

        $byConversation = [];
        foreach ($logs as $log) {
            $conversationId = (int) (($log->changes['conversation_id'] ?? 0));
            if ($conversationId <= 0 || isset($byConversation[$conversationId])) {
                continue;
            }

            $action = (string) $log->action;
            $label = $actions[$action] ?? str_replace('.', ' ', $action);
            $byConversation[$conversationId] = [
                'label' => 'Ação: ' . $label,
                'created_at' => $log->created_at,
            ];
        }

        return $byConversation;
    }

    /**
     * @return array<string, string>
     */
    private function conversationActionLabels(): array
    {
        return [
            'company.conversation.created' => 'Conversa criada',
            'company.conversation.assumed' => 'Conversa assumida',
            'company.conversation.released' => 'Conversa solta',
            'company.conversation.transferred' => 'Conversa transferida',
            'company.conversation.closed' => 'Conversa encerrada',
            'company.conversation.manual_reply' => 'Resposta manual enviada',
            'company.conversation.send_template' => 'Template enviado',
            'company.conversation.contact_updated' => 'Contato atualizado',
            'company.conversation.tags_updated' => 'Tags atualizadas',
            'company.conversation.tag_attached' => 'Tag adicionada',
            'company.conversation.tag_detached' => 'Tag removida',
            'admin.conversation.contact_updated' => 'Contato atualizado por admin',
        ];
    }

    private function resolveRelevance(
        string $customerPhone,
        string $queryText,
        bool $matchedMessage,
        bool $matchedAction
    ): int {
        if (Str::contains($customerPhone, preg_replace('/\s+/u', '', $queryText))) {
            return 0;
        }

        if ($matchedMessage) {
            return 1;
        }

        if ($matchedAction) {
            return 2;
        }

        return 3;
    }
}

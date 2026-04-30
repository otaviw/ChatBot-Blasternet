<?php

declare(strict_types=1);


namespace App\Actions\Conversation;

use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

class SyncConversationTagsAction
{
    public function __construct(
        private readonly AuditLogService $auditLog
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{conversation: Conversation, tags: array<int, string>}|null
     */
    public function handle(Request $request, User $user, int $conversationId, array $validated): ?array
    {
        $conversation = Conversation::query()
            ->where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return null;
        }

        $tags = collect($validated['tags'] ?? [])
            ->map(fn ($tag) => strtolower(trim((string) $tag)))
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

        return [
            'conversation' => $conversation,
            'tags' => $tags,
        ];
    }
}


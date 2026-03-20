<?php

namespace App\Actions\Company\Ai;

use App\Models\User;
use App\Services\Ai\InternalAiConversationService;

class CreateCompanyAiConversationAction
{
    public function __construct(
        private readonly InternalAiConversationService $conversationService
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function handle(User $user, array $validated): array
    {
        $this->conversationService->ensureInternalChatEnabled($user);

        $conversation = $this->conversationService->createForUser(
            $user,
            isset($validated['title']) ? (string) $validated['title'] : null
        );

        $conversation->setRelation('lastMessage', null);

        return [
            'ok' => true,
            'conversation' => $this->conversationService->serializeConversationSummary($conversation),
        ];
    }
}

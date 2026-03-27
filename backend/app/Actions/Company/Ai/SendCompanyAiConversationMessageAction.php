<?php

namespace App\Actions\Company\Ai;

use App\Models\User;
use App\Services\Ai\InternalAiChatService;
use App\Services\Ai\InternalAiConversationService;

class SendCompanyAiConversationMessageAction
{
    public function __construct(
        private readonly InternalAiConversationService $conversationService,
        private readonly InternalAiChatService $internalAiChatService
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    public function handle(User $user, int $conversationId, array $validated): ?array
    {
        $this->conversationService->ensureInternalChatEnabled($user);

        $conversation = $this->conversationService->findForUser($user, $conversationId);
        if (! $conversation) {
            return null;
        }

        $content = (string) ($validated['content'] ?? $validated['text'] ?? '');

        $result = $this->internalAiChatService->sendMessage($user, $content, $conversation);

        $resultConversation = $result['conversation'];
        $assistantMessage = $result['assistant_message'];
        $resultConversation->setRelation('lastMessage', $assistantMessage);

        return [
            'ok' => true,
            'conversation' => $this->conversationService->serializeConversationSummary($resultConversation),
            'user_message' => $this->conversationService->serializeMessage($result['user_message']),
            'assistant_message' => $this->conversationService->serializeMessage($assistantMessage),
            'provider' => (string) $result['provider'],
            'model' => $result['model'] !== null ? (string) $result['model'] : null,
        ];
    }
}

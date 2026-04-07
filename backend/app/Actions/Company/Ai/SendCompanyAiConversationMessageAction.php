<?php

namespace App\Actions\Company\Ai;

use App\Models\User;
use App\Services\Ai\InternalAiChatService;
use App\Services\Ai\InternalAiConversationService;
use Illuminate\Http\Request;

class SendCompanyAiConversationMessageAction
{
    public function __construct(
        private readonly InternalAiConversationService $conversationService,
        private readonly InternalAiChatService $internalAiChatService
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(User $user, int $conversationId, Request $request): ?array
    {
        $companyId = $user->isSystemAdmin() ? ((int) $request->input('company_id', 0) ?: null) : null;
        $this->conversationService->ensureInternalChatEnabled($user, $companyId);

        $conversation = $this->conversationService->findForUser($user, $conversationId, $companyId);
        if (! $conversation) {
            return null;
        }

        $content = (string) ($request->input('content') ?? $request->input('text') ?? '');

        $result = $this->internalAiChatService->sendMessage($user, $content, $conversation, $companyId);

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

<?php

namespace App\Actions\Company\Ai;

use App\Models\User;
use App\Services\Ai\InternalAiConversationService;
use Illuminate\Http\Request;

class CreateCompanyAiConversationAction
{
    public function __construct(
        private readonly InternalAiConversationService $conversationService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user, Request $request): array
    {
        $companyId = $user->isSystemAdmin() ? ((int) $request->input('company_id', 0) ?: null) : null;
        $this->conversationService->ensureInternalChatEnabled($user, $companyId);
        $title = $request->has('title') ? (string) $request->input('title') : null;

        $conversation = $this->conversationService->createForUser(
            $user,
            $title,
            $companyId
        );

        $conversation->setRelation('lastMessage', null);

        return [
            'ok' => true,
            'conversation' => $this->conversationService->serializeConversationSummary($conversation),
        ];
    }
}

<?php

namespace App\Actions\Company\Conversation;

use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use App\Services\Ai\ConversationAiSuggestionService;
use App\Services\Company\CompanyConversationSupportService;
use Illuminate\Validation\ValidationException;

class GenerateAiSuggestionForConversationAction
{
    public function __construct(
        private readonly CompanyConversationSupportService $conversationSupport,
        private readonly AiAccessService $aiAccessService,
        private readonly ConversationAiSuggestionService $suggestionService
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(User $user, int $conversationId): ?array
    {
        $settings = $this->aiAccessService->resolveCompanySettings($user);
        $this->aiAccessService->assertCanUseInternalAi($user, $settings);
        if (! $settings instanceof CompanyBotSetting) {
            throw ValidationException::withMessages([
                'ai' => ['IA interna não está habilitada para esta empresa.'],
            ]);
        }

        $companyId = (int) ($user->company_id ?? 0);

        $query = Conversation::query()
            ->where('company_id', $companyId)
            ->whereKey($conversationId);
        $this->conversationSupport->applyInboxVisibilityScope($query, $user);

        $conversation = $query->first();
        if (! $conversation) {
            return null;
        }

        $result = $this->suggestionService->generateSuggestion($conversation, $settings);

        return [
            'ok'               => true,
            'suggestion'       => $result['suggestion'],
            'confidence_score' => $result['confidence_score'],
            'used_rag'         => $result['used_rag'],
        ];
    }
}

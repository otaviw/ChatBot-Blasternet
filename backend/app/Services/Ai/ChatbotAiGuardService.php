<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\CompanyBotSetting;

class ChatbotAiGuardService
{
    public function __construct(
        private readonly ResellerAiCompanyPermissionService $permissionService,
        private readonly ?bool $globalFlagOverride = null,
    ) {}

    public function canUseAiForBot(Company $company, ?Conversation $conversation = null, array $context = []): bool
    {
        return (bool) $this->gateResult($company, $conversation, $context)['allowed'];
    }

    /**
     * @return array{
     *   allowed: bool,
     *   reasons: list<string>,
     *   gates: array{
     *      global_feature_enabled: bool,
     *      reseller_authorized: bool,
     *      company_ai_enabled: bool
     *   },
     *   context: array<string, mixed>
     * }
     */
    public function gateResult(Company $company, ?Conversation $conversation = null, array $context = []): array
    {
        $settings = $this->resolveSettings($company);
        $globalEnabled = $this->resolveGlobalFeatureFlag();
        $companyAiEnabled = (bool) ($settings?->ai_chatbot_enabled ?? false);
        $resellerAuthorized = $this->permissionService->isCompanyAllowed(
            (int) ($company->reseller_id ?? 0),
            (int) ($company->id ?? 0),
        );

        $gates = [
            'global_feature_enabled' => $globalEnabled,
            'reseller_authorized' => $resellerAuthorized,
            'company_ai_enabled' => $companyAiEnabled,
        ];

        $allowed = $globalEnabled && $resellerAuthorized && $companyAiEnabled;
        $reasons = [];

        if (! $globalEnabled) {
            $reasons[] = 'global_feature_disabled';
        }

        if (! $resellerAuthorized) {
            $reasons[] = 'reseller_not_authorized';
        }

        if (! $companyAiEnabled) {
            $reasons[] = 'company_ai_disabled';
        }

        if ($allowed) {
            $reasons[] = 'allowed';
        }

        return [
            'allowed' => $allowed,
            'reasons' => $reasons,
            'gates' => $gates,
            'context' => array_merge([
                'company_id' => (int) ($company->id ?? 0),
                'reseller_id' => (int) ($company->reseller_id ?? 0),
                'conversation_id' => $conversation?->id ? (int) $conversation->id : null,
            ], $context),
        ];
    }

    private function resolveGlobalFeatureFlag(): bool
    {
        if ($this->globalFlagOverride !== null) {
            return $this->globalFlagOverride;
        }

        return (bool) config('ai.chatbot_feature_enabled', false);
    }

    private function resolveSettings(Company $company): ?CompanyBotSetting
    {
        if ($company->relationLoaded('botSetting')) {
            $loaded = $company->getRelation('botSetting');

            return $loaded instanceof CompanyBotSetting ? $loaded : null;
        }

        return $company->botSetting()->first();
    }
}

<?php

declare(strict_types=1);


namespace App\Services\Ai;

use App\Models\CompanyBotSetting;

/**
 * Métodos de resolução de configurações de provider de IA.
 * Compartilhado entre InternalAiChatService, InternalAiChatStreamService e
 * ConversationAiSuggestionService — todos leem as mesmas chaves de CompanyBotSetting
 * e fazem fallback para as configs globais de app/ai.php.
 */
trait ResolvesAiProviderSettings
{
    private function resolveModelName(?CompanyBotSetting $settings): ?string
    {
        $companyModel = trim((string) ($settings?->ai_model ?? ''));
        if ($companyModel !== '') {
            return $companyModel;
        }

        $globalModel = trim((string) config('ai.model', ''));

        return $globalModel !== '' ? $globalModel : null;
    }

    private function resolveSystemPrompt(): ?string
    {
        $globalPrompt = trim((string) config('ai.system_prompt', ''));

        return $globalPrompt !== '' ? $globalPrompt : null;
    }

    private function resolveTemperature(?CompanyBotSetting $settings): ?float
    {
        if ($settings?->ai_temperature !== null && is_numeric($settings->ai_temperature)) {
            return (float) $settings->ai_temperature;
        }

        $global = config('ai.temperature');
        if ($global !== null && is_numeric($global)) {
            return (float) $global;
        }

        return null;
    }

    private function resolveMaxResponseTokens(?CompanyBotSetting $settings): ?int
    {
        if ($settings?->ai_max_response_tokens !== null && is_numeric($settings->ai_max_response_tokens)) {
            $value = (int) $settings->ai_max_response_tokens;

            return $value > 0 ? $value : null;
        }

        $global = config('ai.max_response_tokens');
        if ($global !== null && is_numeric($global)) {
            $value = (int) $global;

            return $value > 0 ? $value : null;
        }

        return null;
    }
}

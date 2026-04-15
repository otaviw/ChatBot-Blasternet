<?php

namespace App\Services\Ai;

use App\Models\CompanyBotSetting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AiAccessService
{
    public function resolveCompanySettings(User $user): ?CompanyBotSetting
    {
        $companyId = (int) ($user->company_id ?? 0);
        if ($companyId <= 0) {
            return null;
        }

        return CompanyBotSetting::query()
            ->where('company_id', $companyId)
            ->first() ?? new CompanyBotSetting([
                'company_id' => $companyId,
                'ai_enabled' => false,
                'ai_internal_chat_enabled' => false,
                'ai_usage_enabled' => true,
                'ai_usage_limit_monthly' => null,
                'ai_chatbot_enabled' => false,
                'ai_chatbot_auto_reply_enabled' => false,
                'ai_chatbot_rules' => null,
                'ai_max_context_messages' => 10,
                'ai_usage_count' => 0,
                'ai_chatbot_mode' => 'disabled',
            ]);
    }

    /**
     * Verifica se o usuário pode acessar e editar as configurações do bot/IA da empresa.
     * Não exige ai_enabled — qualquer company_admin ativo pode configurar (inclusive habilitar IA).
     * Resolve o catch-22 onde era preciso ter IA habilitada para poder habilitá-la.
     */
    public function canAccessBotSettings(User $user): bool
    {
        if (! (bool) $user->is_active) {
            return false;
        }

        if ($user->isSystemAdmin()) {
            return true;
        }

        $normalizedRole = User::normalizeRole((string) $user->role);

        return $normalizedRole === User::ROLE_COMPANY_ADMIN;
    }

    /**
     * Verifica se o usuário pode usar funcionalidades de IA (requer ai_enabled = true na empresa).
     * Usado para proteger endpoints que consumem IA, não a tela de configuração.
     */
    public function canManageAi(User $user): bool
    {
        if (! (bool) $user->is_active) {
            return false;
        }

        if ($user->isSystemAdmin()) {
            return true;
        }

        $normalizedRole = User::normalizeRole((string) $user->role);
        if ($normalizedRole !== User::ROLE_COMPANY_ADMIN) {
            return false;
        }

        $settings = $this->resolveCompanySettings($user);

        return (bool) ($settings?->ai_enabled ?? false);
    }

    public function companyAllowsInternalAi(?CompanyBotSetting $settings): bool
    {
        return (bool) ($settings?->ai_enabled ?? false)
            && (bool) ($settings?->ai_internal_chat_enabled ?? false);
    }

    public function canUseInternalAi(User $user, ?CompanyBotSetting $settings = null): bool
    {
        if (! (bool) $user->is_active) {
            return false;
        }

        if ($user->isSystemAdmin()) {
            return true;
        }

        $normalizedRole = User::normalizeRole((string) $user->role);
        if (! in_array($normalizedRole, [User::ROLE_COMPANY_ADMIN, User::ROLE_AGENT], true)) {
            return false;
        }

        $effectiveSettings = $settings ?? $this->resolveCompanySettings($user);
        if (! $this->companyAllowsInternalAi($effectiveSettings)) {
            return false;
        }

        if ($normalizedRole === User::ROLE_COMPANY_ADMIN) {
            return true;
        }

        return (bool) $user->can_use_ai;
    }

    public function assertCanUseInternalAi(User $user, ?CompanyBotSetting $settings = null): void
    {
        if ($this->canUseInternalAi($user, $settings)) {
            return;
        }

        $normalizedRole = User::normalizeRole((string) $user->role);
        $effectiveSettings = $settings ?? $this->resolveCompanySettings($user);

        if (! $this->companyAllowsInternalAi($effectiveSettings)) {
            throw ValidationException::withMessages([
                'ai' => ['IA interna não está habilitada para esta empresa.'],
            ]);
        }

        if ($normalizedRole === User::ROLE_AGENT && ! (bool) $user->can_use_ai) {
            throw ValidationException::withMessages([
                'user' => ['Usuário não possui permissão para usar IA interna.'],
            ]);
        }

        throw ValidationException::withMessages([
            'user' => ['Usuário não possui permissão para usar IA interna.'],
        ]);
    }
}

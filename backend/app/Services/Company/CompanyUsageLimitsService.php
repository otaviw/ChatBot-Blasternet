<?php

namespace App\Services\Company;

use App\Models\CompanyBotSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Manages per-company monthly usage limits.
 *
 * Supported limit types:
 *   - 'conversation' : outbound messages on open conversations (manual reply)
 *   - 'template'     : template messages that initiate new conversations
 *   - 'user'         : total active company users (not monthly, checked at creation)
 *
 * Usage:
 *   $result = $service->checkAndConsume($companyId, 'conversation');
 *   if (!$result['allowed']) return error...
 */
class CompanyUsageLimitsService
{
    /** Percentage of limit at which a warning is emitted (0-100). */
    private const WARNING_THRESHOLD = 80;

    /**
     * Check whether the company is within its limit and atomically increment the counter.
     *
     * Returns:
     *   ['allowed' => true,  'warning' => false, 'used' => N, 'limit' => M]
     *   ['allowed' => true,  'warning' => true,  'used' => N, 'limit' => M, 'warning_message' => '...']
     *   ['allowed' => false, 'used' => N, 'limit' => M, 'error_message' => '...']
     */
    public function checkAndConsume(int $companyId, string $type): array
    {
        return DB::transaction(function () use ($companyId, $type) {
            /** @var CompanyBotSetting|null $settings */
            $settings = CompanyBotSetting::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();

            if (! $settings) {
                // No settings record — allow without tracking
                return ['allowed' => true, 'warning' => false, 'used' => 0, 'limit' => null];
            }

            $this->maybeResetCounters($settings);

            [$limitField, $usedField] = $this->fields($type);

            $limit = $settings->{$limitField};
            $used  = (int) $settings->{$usedField};

            if ($limit !== null && $used >= (int) $limit) {
                return [
                    'allowed'       => false,
                    'used'          => $used,
                    'limit'         => (int) $limit,
                    'error_message' => $this->blockedMessage($type, (int) $limit),
                ];
            }

            // Increment atomically (we already hold the lock)
            $settings->{$usedField} = $used + 1;
            $settings->save();

            $newUsed = $used + 1;
            $warning = $limit !== null && ($newUsed / (int) $limit) >= (self::WARNING_THRESHOLD / 100);

            return [
                'allowed'         => true,
                'warning'         => $warning,
                'used'            => $newUsed,
                'limit'           => $limit !== null ? (int) $limit : null,
                'warning_message' => $warning ? $this->warningMessage($type, $newUsed, (int) $limit) : null,
            ];
        });
    }

    /**
     * Check user count limit without consuming (users are not monthly-tracked).
     * Call this before User::create().
     */
    public function checkUserLimit(int $companyId): array
    {
        $settings = CompanyBotSetting::query()
            ->where('company_id', $companyId)
            ->first();

        if (! $settings || $settings->max_users === null) {
            return ['allowed' => true, 'warning' => false, 'current' => null, 'limit' => null];
        }

        $current = User::query()
            ->where('company_id', $companyId)
            ->whereIn('role', User::companyRoleValues())
            ->count();

        $limit = (int) $settings->max_users;

        if ($current >= $limit) {
            return [
                'allowed'       => false,
                'current'       => $current,
                'limit'         => $limit,
                'error_message' => "Limite de usuários atingido ({$current}/{$limit}). Contate o suporte para aumentar seu plano.",
            ];
        }

        $warning = ($current + 1) / $limit >= (self::WARNING_THRESHOLD / 100);

        return [
            'allowed'         => true,
            'warning'         => $warning,
            'current'         => $current + 1,
            'limit'           => $limit,
            'warning_message' => $warning
                ? "Você está próximo do limite de usuários (" . ($current + 1) . "/{$limit})."
                : null,
        ];
    }

    /**
     * Return current usage snapshot for a company (for API responses / frontend display).
     */
    public function snapshot(int $companyId): array
    {
        $settings = CompanyBotSetting::query()
            ->where('company_id', $companyId)
            ->first();

        if (! $settings) {
            return [
                'max_users'                        => null,
                'max_conversation_messages_monthly' => null,
                'max_template_messages_monthly'     => null,
                'conversation_messages_used'        => 0,
                'template_messages_used'            => 0,
                'reset_month'                       => null,
                'reset_year'                        => null,
            ];
        }

        $this->maybeResetCounters($settings);

        $currentUsers = User::query()
            ->where('company_id', $companyId)
            ->whereIn('role', User::companyRoleValues())
            ->count();

        return [
            'max_users'                         => $settings->max_users,
            'max_conversation_messages_monthly'  => $settings->max_conversation_messages_monthly,
            'max_template_messages_monthly'      => $settings->max_template_messages_monthly,
            'conversation_messages_used'         => (int) $settings->conversation_messages_used,
            'template_messages_used'             => (int) $settings->template_messages_used,
            'current_users'                      => $currentUsers,
            'reset_month'                        => $settings->usage_reset_month ?: null,
            'reset_year'                         => $settings->usage_reset_year ?: null,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function maybeResetCounters(CompanyBotSetting $settings): void
    {
        $now   = now();
        $month = (int) $now->month;
        $year  = (int) $now->year;

        if ($settings->usage_reset_month === $month && $settings->usage_reset_year === $year) {
            return;
        }

        $settings->conversation_messages_used = 0;
        $settings->template_messages_used     = 0;
        $settings->usage_reset_month          = $month;
        $settings->usage_reset_year           = $year;
        // save() is called by the caller (inside the transaction for consume, or standalone for snapshot)
        $settings->save();
    }

    /** @return array{0: string, 1: string} [limitField, usedField] */
    private function fields(string $type): array
    {
        return match ($type) {
            'conversation' => ['max_conversation_messages_monthly', 'conversation_messages_used'],
            'template'     => ['max_template_messages_monthly', 'template_messages_used'],
            default        => throw new \InvalidArgumentException("Unknown limit type: {$type}"),
        };
    }

    private function blockedMessage(string $type, int $limit): string
    {
        return match ($type) {
            'conversation' => "Limite mensal de mensagens de conversa atingido ({$limit}). Contate o suporte para aumentar seu plano.",
            'template'     => "Limite mensal de mensagens de template atingido ({$limit}). Contate o suporte para aumentar seu plano.",
            default        => "Limite atingido.",
        };
    }

    private function warningMessage(string $type, int $used, int $limit): string
    {
        $pct = (int) round(($used / $limit) * 100);

        return match ($type) {
            'conversation' => "Atenção: você usou {$pct}% do limite mensal de mensagens de conversa ({$used}/{$limit}).",
            'template'     => "Atenção: você usou {$pct}% do limite mensal de mensagens de template ({$used}/{$limit}).",
            default        => "Atenção: {$pct}% do limite utilizado ({$used}/{$limit}).",
        };
    }
}

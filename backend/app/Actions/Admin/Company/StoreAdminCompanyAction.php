<?php

namespace App\Actions\Admin\Company;

use App\Data\ActionResponse;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\Reseller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Bot\BotSettingsSupportService;
use App\Services\ProductMetricsService;
use App\Support\ProductFunnels;
use Illuminate\Support\Facades\Log;

class StoreAdminCompanyAction
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly BotSettingsSupportService $botSettingsSupport,
        private readonly ProductMetricsService $productMetrics,
    ) {}

    public function handle(StoreCompanyRequest $request): ActionResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if ($user->isSystemAdmin()) {
            $resellerId = $validated['reseller_id'] ?? Reseller::getBySlug('default')?->id;

            if ($resellerId === null) {
                Log::warning('Company criada sem reseller_id: reseller default não encontrado. Execute DefaultResellerSeeder.');
            }
        } else {
            $resellerId = (int) ($user->reseller_id ?? $user->company?->reseller_id ?? 0);

            if ($resellerId <= 0) {
                return ActionResponse::forbidden('Usuário sem reseller vinculado.');
            }
        }

        $company = Company::create([
            'name'                 => $validated['name'],
            'meta_phone_number_id' => $validated['meta_phone_number_id'] ?? null,
            'meta_waba_id'         => $validated['meta_waba_id'] ?? null,
            'reseller_id'          => $resellerId,
        ]);

        $defaults = $this->botSettingsSupport->defaultBotSettingsPayload($company->id);

        CompanyBotSetting::firstOrCreate(
            ['company_id' => $company->id],
            array_merge($defaults, [
                'ai_enabled'                        => (bool) ($validated['ai_enabled'] ?? $defaults['ai_enabled']),
                'ai_internal_chat_enabled'          => (bool) ($validated['ai_internal_chat_enabled'] ?? $defaults['ai_internal_chat_enabled']),
                'ai_chatbot_enabled'                => (bool) ($validated['ai_chatbot_enabled'] ?? $defaults['ai_chatbot_enabled']),
                'ai_chatbot_auto_reply_enabled'     => (bool) ($validated['ai_chatbot_auto_reply_enabled'] ?? $defaults['ai_chatbot_auto_reply_enabled']),
                'ai_chatbot_rules'                  => $validated['ai_chatbot_rules'] ?? $defaults['ai_chatbot_rules'],
                'ai_usage_enabled'                  => (bool) ($validated['ai_usage_enabled'] ?? $defaults['ai_usage_enabled']),
                'ai_usage_limit_monthly'            => $validated['ai_usage_limit_monthly'] ?? $defaults['ai_usage_limit_monthly'],
                'max_users'                         => $validated['max_users'] ?? $defaults['max_users'],
                'max_conversation_messages_monthly' => $validated['max_conversation_messages_monthly'] ?? $defaults['max_conversation_messages_monthly'],
                'max_template_messages_monthly'     => $validated['max_template_messages_monthly'] ?? $defaults['max_template_messages_monthly'],
            ])
        );

        $this->auditLog->record(
            $request,
            'admin.company.created',
            $company->id,
            [
                'name' => $company->name,
                'meta_phone_number_id' => $company->meta_phone_number_id,
            ]
        );

        $this->productMetrics->track(
            ProductFunnels::CADASTRO,
            'company_created',
            'admin_company_created',
            (int) $company->id,
            $user?->id ? (int) $user->id : null,
            [
                'reseller_id' => $resellerId ?? null,
                'created_by_role' => $user ? User::normalizeRole((string) $user->role) : null,
            ],
        );

        return ActionResponse::created(['ok' => true, 'company' => $company->load('botSetting')]);
    }
}

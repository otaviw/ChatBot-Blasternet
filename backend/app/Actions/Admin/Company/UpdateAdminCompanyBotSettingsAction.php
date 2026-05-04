<?php

declare(strict_types=1);


namespace App\Actions\Admin\Company;

use App\Http\Requests\Admin\UpdateCompanyBotSettingsRequest;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Services\AuditLogService;
use App\Services\Bot\AiSettingsPayloadBuilder;
use App\Services\Bot\BotSettingsSupportService;
use App\Services\Ai\AiAccessService;

class UpdateAdminCompanyBotSettingsAction
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly AiSettingsPayloadBuilder $aiSettingsPayloadBuilder,
        private readonly BotSettingsSupportService $botSettingsSupport,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(UpdateCompanyBotSettingsRequest $request, Company $company): array
    {
        $validated = $request->validated();

        $settingsPayload = [
            'is_active' => (bool) $validated['is_active'],
            'timezone' => $validated['timezone'],
            'welcome_message' => $validated['welcome_message'] ?? null,
            'fallback_message' => $validated['fallback_message'] ?? null,
            'out_of_hours_message' => $validated['out_of_hours_message'] ?? null,
            'business_hours' => $this->botSettingsSupport->normalizeBusinessHours($validated['business_hours']),
            'keyword_replies' => $this->botSettingsSupport->normalizeKeywordReplies($validated['keyword_replies'] ?? []),
            'service_areas' => $this->botSettingsSupport->normalizeServiceAreas($validated['service_areas'] ?? []),
            'stateful_menu_flow' => is_array($validated['stateful_menu_flow'] ?? null) ? $validated['stateful_menu_flow'] : null,
            'inactivity_close_hours' => $this->botSettingsSupport->resolveInactivityCloseHours(
                array_key_exists('inactivity_close_hours', $validated) ? $validated['inactivity_close_hours'] : null,
                $company->botSetting?->inactivity_close_hours
            ),
        ];

        $settingsPayload = array_merge(
            $settingsPayload,
            $this->aiSettingsPayloadBuilder->fromValidated($validated, [
                'ai_enabled',
                'ai_internal_chat_enabled',
                'ai_chatbot_enabled',
                'ai_chatbot_shadow_mode',
                'ai_chatbot_sandbox_enabled',
                'ai_chatbot_test_numbers',
                'ai_chatbot_confidence_threshold',
                'ai_chatbot_handoff_repeat_limit',
                'ai_chatbot_auto_reply_enabled',
                'ai_chatbot_rules',
                'ai_usage_enabled',
                'ai_usage_limit_monthly',
                'max_users',
                'max_conversation_messages_monthly',
                'max_template_messages_monthly',
            ])
        );

        $settings = CompanyBotSetting::updateOrCreate(
            ['company_id' => $company->id],
            $settingsPayload
        );

        $this->botSettingsSupport->syncServiceAreas($company->id, $settings->service_areas ?? []);
        AiAccessService::forgetCompanyBotSettingsCache((int) $company->id);

        $this->auditLog->record(
            $request,
            'admin.company.bot_settings.updated',
            $company->id,
            [
                'is_active' => $settings->is_active,
                'timezone' => $settings->timezone,
                'keyword_replies_count' => count($settings->keyword_replies ?? []),
            ],
            [
                'target_company_id' => $company->id,
            ]
        );

        return [
            'ok' => true,
            'settings' => $settings,
        ];
    }
}

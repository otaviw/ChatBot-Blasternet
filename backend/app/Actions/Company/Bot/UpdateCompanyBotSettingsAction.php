<?php

declare(strict_types=1);


namespace App\Actions\Company\Bot;

use App\Data\ActionResponse;
use App\Http\Requests\Company\UpdateBotSettingsRequest;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Services\AuditLogService;
use App\Services\Ai\AiAccessService;
use App\Services\Bot\AiSettingsPayloadBuilder;
use App\Services\Bot\BotSettingsSupportService;
use App\Services\CriticalAuditLogService;
use App\Services\WhatsAppCredentialsValidatorService;

class UpdateCompanyBotSettingsAction
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly CriticalAuditLogService $criticalAuditLog,
        private readonly AiSettingsPayloadBuilder $aiSettingsPayloadBuilder,
        private readonly BotSettingsSupportService $botSettingsSupport,
        private readonly WhatsAppCredentialsValidatorService $credentialsValidator,
    ) {}

    public function handle(UpdateBotSettingsRequest $request, Company $company): ActionResponse
    {
        $validated = $request->validated();

        $credentialsChanged = false;
        $credentialError = $this->handleCredentials($company, $validated, $credentialsChanged);
        if ($credentialError !== null) {
            return ActionResponse::unprocessable($credentialError);
        }

        $settings = CompanyBotSetting::updateOrCreate(
            ['company_id' => $company->id],
            array_merge(
                [
                    'is_active'              => (bool) $validated['is_active'],
                    'timezone'               => $validated['timezone'],
                    'welcome_message'        => $validated['welcome_message'] ?? null,
                    'fallback_message'       => $validated['fallback_message'] ?? null,
                    'out_of_hours_message'   => $validated['out_of_hours_message'] ?? null,
                    'business_hours'         => $this->botSettingsSupport->normalizeBusinessHours($validated['business_hours']),
                    'keyword_replies'        => $this->botSettingsSupport->normalizeKeywordReplies($validated['keyword_replies'] ?? []),
                    'service_areas'          => $this->botSettingsSupport->normalizeServiceAreas($validated['service_areas'] ?? []),
                    'stateful_menu_flow'     => is_array($validated['stateful_menu_flow'] ?? null) ? $validated['stateful_menu_flow'] : null,
                    'inactivity_close_hours' => $this->botSettingsSupport->resolveInactivityCloseHours(
                        array_key_exists('inactivity_close_hours', $validated) ? $validated['inactivity_close_hours'] : null,
                        $company->botSetting?->inactivity_close_hours
                    ),
                    'message_retention_days' => (int) $validated['message_retention_days'],
                ],
                $this->aiSettingsPayloadBuilder->fromValidated($validated, $this->aiSettingsPayloadBuilder->fieldNames())
            )
        );

        $this->botSettingsSupport->syncServiceAreas($company->id, $settings->service_areas ?? []);
        AiAccessService::forgetCompanyBotSettingsCache((int) $company->id);

        $this->auditLog->record(
            $request,
            'company.bot_settings.updated',
            $company->id,
            [
                'is_active'             => $settings->is_active,
                'timezone'              => $settings->timezone,
                'keyword_replies_count' => count($settings->keyword_replies ?? []),
            ]
        );

        if ($credentialsChanged) {
            $this->criticalAuditLog->record(
                $request,
                'settings.integrations_config_updated',
                (int) $company->id
            );
        }

        return ActionResponse::ok(['ok' => true, 'settings' => $settings]);
    }

    /**
     * Valida e persiste credenciais WhatsApp quando enviadas e alteradas.
     * Retorna uma mensagem de erro ou null quando tudo estiver ok.
     */
    private function handleCredentials(Company $company, array $validated, bool &$credentialsChanged): ?string
    {
        $credentialsChanged = false;
        $hasPhone = array_key_exists('meta_phone_number_id', $validated);
        $hasToken = array_key_exists('meta_access_token', $validated);

        if (! $hasPhone && ! $hasToken) {
            return null;
        }

        $newPhoneId = $hasPhone && $validated['meta_phone_number_id'] !== null
            ? trim((string) $validated['meta_phone_number_id'])
            : null;

        $newToken = $hasToken && $validated['meta_access_token'] !== null
            ? trim((string) $validated['meta_access_token'])
            : null;

        $phoneChanged = $newPhoneId !== null && $newPhoneId !== '' && $newPhoneId !== (string) ($company->meta_phone_number_id ?? '');
        $tokenChanged = $newToken !== null && $newToken !== '' && $newToken !== (string) ($company->meta_access_token ?? '');

        if ($phoneChanged || $tokenChanged) {
            $phoneToValidate = ($newPhoneId !== null && $newPhoneId !== '') ? $newPhoneId : (string) ($company->meta_phone_number_id ?? '');
            $tokenToValidate = ($newToken !== null && $newToken !== '') ? $newToken : (string) ($company->meta_access_token ?? '');

            $validation = $this->credentialsValidator->validate($phoneToValidate, $tokenToValidate);
            if (! $validation['ok']) {
                return 'Credenciais do WhatsApp inválidas: ' . $validation['error'];
            }
        }

        if ($newPhoneId !== null && $newPhoneId !== '') {
            $company->meta_phone_number_id = $newPhoneId;
        }
        if ($newToken !== null && $newToken !== '') {
            $company->meta_access_token = $newToken;
        }
        if ($company->isDirty()) {
            $credentialsChanged = true;
            $company->save();
        }

        return null;
    }
}

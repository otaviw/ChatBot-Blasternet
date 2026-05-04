<?php

declare(strict_types=1);


namespace App\Actions\Admin\Company;

use App\Data\ActionResponse;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Services\AuditLogService;
use App\Services\Bot\AiSettingsPayloadBuilder;
use App\Services\WhatsAppCredentialsValidatorService;

class UpdateAdminCompanyAction
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly AiSettingsPayloadBuilder $aiSettingsPayloadBuilder,
        private readonly WhatsAppCredentialsValidatorService $credentialsValidator,
    ) {}

    public function handle(UpdateCompanyRequest $request, Company $company): ActionResponse
    {
        $validated = $request->validated();

        $before = [
            'name' => $company->name,
            'meta_phone_number_id' => $company->meta_phone_number_id,
            'has_meta_credentials' => $company->hasMetaCredentials(),
        ];

        $newPhoneId = $validated['meta_phone_number_id'] ?? null;
        $newToken   = array_key_exists('meta_access_token', $validated) ? ($validated['meta_access_token'] ?: null) : null;

        $phoneChanged = $newPhoneId !== null && $newPhoneId !== '' && $newPhoneId !== (string) ($company->meta_phone_number_id ?? '');
        $tokenChanged = $newToken !== null && $newToken !== (string) ($company->meta_access_token ?? '');

        if ($phoneChanged || $tokenChanged) {
            $phoneToValidate = $newPhoneId ?: (string) ($company->meta_phone_number_id ?? '');
            $tokenToValidate = $newToken   ?: (string) ($company->meta_access_token    ?? config('whatsapp.access_token', ''));

            if ($phoneToValidate !== '' && $tokenToValidate !== '') {
                $validation = $this->credentialsValidator->validate($phoneToValidate, $tokenToValidate);
                if (! $validation['ok']) {
                    return ActionResponse::unprocessable('Credenciais do WhatsApp inválidas: ' . $validation['error']);
                }
            }
        }

        $company->name = $validated['name'];
        $company->meta_phone_number_id = $newPhoneId;
        $company->meta_waba_id = $validated['meta_waba_id'] ?? null;
        if ($newToken !== null) {
            $company->meta_access_token = $newToken;
        }
        $company->save();
        $company->refresh();

        $aiSettingsPayload = $this->aiSettingsPayloadBuilder->fromValidated($validated, [
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
        ]);

        if ($aiSettingsPayload !== []) {
            CompanyBotSetting::updateOrCreate(
                ['company_id' => $company->id],
                $aiSettingsPayload
            );
            $company->load('botSetting');
        }

        $this->auditLog->record(
            $request,
            'admin.company.updated',
            $company->id,
            [
                'before' => $before,
                'after' => [
                    'name' => $company->name,
                    'meta_phone_number_id' => $company->meta_phone_number_id,
                    'has_meta_credentials' => $company->hasMetaCredentials(),
                ],
            ]
        );

        return ActionResponse::ok(['ok' => true, 'company' => $company->loadMissing('botSetting')]);
    }
}

<?php

declare(strict_types=1);


namespace App\Actions\Admin\Company;

use App\Data\ActionResponse;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Services\AuditLogService;
use App\Services\Bot\AiSettingsPayloadBuilder;
use App\Services\IxcCredentialsValidatorService;
use App\Services\WhatsAppCredentialsValidatorService;

class UpdateAdminCompanyAction
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly AiSettingsPayloadBuilder $aiSettingsPayloadBuilder,
        private readonly WhatsAppCredentialsValidatorService $credentialsValidator,
        private readonly IxcCredentialsValidatorService $ixcCredentialsValidator,
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
        $newIxcBaseUrl = array_key_exists('ixc_base_url', $validated) ? ($validated['ixc_base_url'] ?: null) : $company->ixc_base_url;
        $newIxcToken = array_key_exists('ixc_api_token', $validated) ? ($validated['ixc_api_token'] ?: null) : $company->ixc_api_token;
        $newIxcSelfSigned = (bool) ($validated['ixc_self_signed'] ?? $company->ixc_self_signed ?? config('ixc.allow_self_signed_default', false));
        $newIxcTimeout = (int) ($validated['ixc_timeout_seconds'] ?? $company->ixc_timeout_seconds ?? config('ixc.default_timeout_seconds', 15));
        $newIxcEnabled = (bool) ($validated['ixc_enabled'] ?? $company->ixc_enabled);

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

        if ($newIxcEnabled) {
            $ixcValidation = $this->ixcCredentialsValidator->validate(
                (string) ($newIxcBaseUrl ?? ''),
                (string) ($newIxcToken ?? ''),
                $newIxcSelfSigned,
                $newIxcTimeout,
            );

            if (! $ixcValidation['ok']) {
                return ActionResponse::unprocessable('Credenciais da IXC invalidas: ' . $ixcValidation['error']);
            }

            $company->ixc_last_validated_at = now();
            $company->ixc_last_validation_error = null;
        }

        $company->name = $validated['name'];
        $company->meta_phone_number_id = $newPhoneId;
        $company->meta_waba_id = $validated['meta_waba_id'] ?? null;
        if ($newToken !== null) {
            $company->meta_access_token = $newToken;
        }
        $company->ixc_base_url = $newIxcBaseUrl;
        if (array_key_exists('ixc_api_token', $validated)) {
            $company->ixc_api_token = $newIxcToken;
        }
        $company->ixc_self_signed = $newIxcSelfSigned;
        $company->ixc_timeout_seconds = max(5, min(60, $newIxcTimeout));
        $company->ixc_enabled = $newIxcEnabled;
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

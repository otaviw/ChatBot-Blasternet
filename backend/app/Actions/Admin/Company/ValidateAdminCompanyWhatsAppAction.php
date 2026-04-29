<?php

namespace App\Actions\Admin\Company;

use App\Data\ActionResponse;
use App\Http\Requests\Admin\ValidateCompanyWhatsAppRequest;
use App\Models\Company;
use App\Services\WhatsAppCredentialsValidatorService;

class ValidateAdminCompanyWhatsAppAction
{
    public function __construct(
        private readonly WhatsAppCredentialsValidatorService $credentialsValidator
    ) {}

    public function handle(ValidateCompanyWhatsAppRequest $request, Company $company): ActionResponse
    {
        $phoneNumberId = trim((string) ($request->input('phone_number_id') ?? $company->meta_phone_number_id ?? config('whatsapp.phone_number_id', '')));
        $accessToken   = trim((string) ($request->input('access_token')    ?? $company->meta_access_token    ?? config('whatsapp.access_token', '')));

        if ($phoneNumberId === '') {
            return ActionResponse::unprocessable('phone_number_id não configurado.');
        }
        if ($accessToken === '') {
            return ActionResponse::unprocessable('access_token não configurado.');
        }

        $result = $this->credentialsValidator->validate($phoneNumberId, $accessToken);

        return new ActionResponse($result['ok'] ? 200 : 422, $result);
    }
}

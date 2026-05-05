<?php

declare(strict_types=1);

namespace App\Actions\Admin\Company;

use App\Data\ActionResponse;
use App\Http\Requests\Admin\ValidateCompanyIxcRequest;
use App\Models\Company;
use App\Services\IxcCredentialsValidatorService;

class ValidateAdminCompanyIxcAction
{
    public function __construct(
        private readonly IxcCredentialsValidatorService $validator,
    ) {}

    public function handle(ValidateCompanyIxcRequest $request, Company $company): ActionResponse
    {
        $baseUrl = trim((string) ($request->input('base_url') ?? $company->ixc_base_url ?? ''));
        $apiToken = trim((string) ($request->input('api_token') ?? $company->ixc_api_token ?? ''));
        $selfSigned = (bool) ($request->input('self_signed') ?? $company->ixc_self_signed ?? config('ixc.allow_self_signed_default', false));
        $timeout = (int) ($request->input('timeout_seconds') ?? $company->ixc_timeout_seconds ?? config('ixc.default_timeout_seconds', 15));

        $result = $this->validator->validate($baseUrl, $apiToken, $selfSigned, $timeout);

        return $result['ok']
            ? ActionResponse::ok($result)
            : ActionResponse::unprocessable($result['error'] ?? 'Falha ao validar IXC.', $result);
    }
}

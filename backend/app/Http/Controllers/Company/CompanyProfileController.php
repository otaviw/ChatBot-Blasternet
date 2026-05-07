<?php

declare(strict_types=1);

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateMyCompanyIxcRequest;
use App\Http\Requests\Company\ValidateMyCompanyIxcRequest;
use App\Models\Company;
use App\Services\AuditLogService;
use App\Services\IxcCredentialsValidatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyProfileController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly IxcCredentialsValidatorService $ixcValidator,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyAdmin()) {
            return $this->errorResponse('Acesso negado.', 'forbidden', 403);
        }

        $company = Company::find((int) $user->company_id);
        if (! $company) {
            return $this->errorResponse('Empresa não encontrada.', 'not_found', 404);
        }

        return response()->json([
            'ok' => true,
            'company' => $company,
        ]);
    }

    public function update(UpdateMyCompanyIxcRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyAdmin()) {
            return $this->errorResponse('Acesso negado.', 'forbidden', 403);
        }

        $company = Company::find((int) $user->company_id);
        if (! $company) {
            return $this->errorResponse('Empresa não encontrada.', 'not_found', 404);
        }

        $validated = $request->validated();
        $baseUrl = (string) ($validated['ixc_base_url'] ?? $company->ixc_base_url ?? '');
        $apiToken = array_key_exists('ixc_api_token', $validated)
            ? (string) ($validated['ixc_api_token'] ?? '')
            : (string) ($company->ixc_api_token ?? '');
        $selfSigned = (bool) ($validated['ixc_self_signed'] ?? $company->ixc_self_signed ?? config('ixc.allow_self_signed_default', false));
        $timeout = (int) ($validated['ixc_timeout_seconds'] ?? $company->ixc_timeout_seconds ?? config('ixc.default_timeout_seconds', 15));
        $enabled = (bool) ($validated['ixc_enabled'] ?? $company->ixc_enabled);

        if ($enabled) {
            $validation = $this->ixcValidator->validate($baseUrl, $apiToken, $selfSigned, $timeout);
            if (! $validation['ok']) {
                return response()->json([
                    'message' => 'Credenciais da IXC inválidas: ' . ($validation['error'] ?? 'Erro desconhecido.'),
                    'details' => $validation['details'] ?? null,
                ], 422);
            }

            $company->ixc_last_validated_at = now();
            $company->ixc_last_validation_error = null;
        }

        $company->ixc_base_url = trim($baseUrl) !== '' ? $baseUrl : null;
        if (array_key_exists('ixc_api_token', $validated)) {
            $company->ixc_api_token = trim($apiToken) !== '' ? $apiToken : null;
        }
        $company->ixc_self_signed = $selfSigned;
        $company->ixc_timeout_seconds = max(5, min(60, $timeout));
        $company->ixc_enabled = $enabled;
        $company->save();

        $this->auditLog->record(
            $request,
            'company.settings.ixc_updated',
            $company->id,
            [
                'ixc_enabled' => (bool) $company->ixc_enabled,
                'ixc_base_url' => $company->ixc_base_url,
                'ixc_self_signed' => (bool) $company->ixc_self_signed,
                'ixc_timeout_seconds' => (int) $company->ixc_timeout_seconds,
            ]
        );

        return response()->json([
            'ok' => true,
            'company' => $company->refresh(),
        ]);
    }

    public function validateIxc(ValidateMyCompanyIxcRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyAdmin()) {
            return $this->errorResponse('Acesso negado.', 'forbidden', 403);
        }

        $company = Company::find((int) $user->company_id);
        if (! $company) {
            return $this->errorResponse('Empresa não encontrada.', 'not_found', 404);
        }

        $baseUrl = trim((string) ($request->input('base_url') ?? $company->ixc_base_url ?? ''));
        $apiToken = trim((string) ($request->input('api_token') ?? $company->ixc_api_token ?? ''));
        $selfSigned = (bool) ($request->input('self_signed') ?? $company->ixc_self_signed ?? config('ixc.allow_self_signed_default', false));
        $timeout = (int) ($request->input('timeout_seconds') ?? $company->ixc_timeout_seconds ?? config('ixc.default_timeout_seconds', 15));

        $result = $this->ixcValidator->validate($baseUrl, $apiToken, $selfSigned, $timeout);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}

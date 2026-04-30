<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Actions\Company\Bot\UpdateCompanyBotSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateBotSettingsRequest;
use App\Http\Requests\Company\ValidateBotWhatsAppRequest;
use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use App\Services\Bot\BotSettingsSupportService;
use App\Services\Company\CompanyUsageLimitsService;
use App\Services\WhatsAppCredentialsValidatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotController extends Controller
{
    public function __construct(
        private readonly AiAccessService $aiAccess,
        private readonly BotSettingsSupportService $botSettingsSupport,
        private readonly WhatsAppCredentialsValidatorService $credentialsValidator,
        private readonly CompanyUsageLimitsService $usageLimits,
        private readonly UpdateCompanyBotSettingsAction $updateAction,
    ) {}

    /** Configuracoes do bot da empresa logada (respostas, horarios etc.). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->aiAccess->canAccessBotSettings($user)) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $companies = $user->isSystemAdmin()
            ? Company::orderBy('name')->get(['id', 'name'])
            : null;

        $company = $this->resolveCompany($request, $user);
        if ($company instanceof JsonResponse) {
            // Sem company selecionada: admin vê seletor, usuário comum vê erro
            if ($user->isSystemAdmin()) {
                return response()->json([
                    'authenticated' => true,
                    'role'          => 'admin',
                    'is_admin'      => true,
                    'companies'     => $companies,
                    'company'       => null,
                    'settings'      => null,
                ]);
            }

            return $company;
        }

        $settings = $company->botSetting ?: $this->buildDefaultSettings($company->id);

        return response()->json([
            'authenticated' => true,
            'role'          => $user->isSystemAdmin() ? 'admin' : 'company',
            'is_admin'      => $user->isSystemAdmin(),
            'companies'     => $companies,
            'company'       => $company,
            'settings'      => $settings,
        ]);
    }

    public function update(UpdateBotSettingsRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->aiAccess->canAccessBotSettings($user)) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $company = $this->resolveCompany($request, $user);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $result = $this->updateAction->handle($request, $company);

        return $result->toResponse();
    }

    /** Testa as credenciais do WhatsApp contra a API da Meta. */
    public function validateWhatsApp(ValidateBotWhatsAppRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->aiAccess->canAccessBotSettings($user)) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $company = $this->resolveCompany($request, $user);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $phoneNumberId = trim((string) ($request->input('phone_number_id') ?? $company->meta_phone_number_id ?? config('whatsapp.phone_number_id', '')));
        $accessToken   = trim((string) ($request->input('access_token')    ?? $company->meta_access_token    ?? config('whatsapp.access_token', '')));

        if ($phoneNumberId === '') {
            return response()->json(['ok' => false, 'error' => 'phone_number_id não configurado.'], 422);
        }
        if ($accessToken === '') {
            return response()->json(['ok' => false, 'error' => 'access_token não configurado.'], 422);
        }

        $result = $this->credentialsValidator->validate($phoneNumberId, $accessToken);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** Retorna o snapshot de uso da empresa atual (limites + contadores). */
    public function usageSnapshot(Request $request): JsonResponse
    {
        $user = $request->user();

        $companyId = $user->isSystemAdmin()
            ? (int) $request->integer('company_id', (int) ($user->company_id ?? 0))
            : (int) $user->company_id;

        if ($companyId <= 0) {
            return response()->json(['usage' => null]);
        }

        return response()->json([
            'usage' => $this->usageLimits->snapshot($companyId),
        ]);
    }

    /**
     * Resolve a empresa a partir do request e do usuário autenticado.
     * Admins passam company_id via query; usuários de empresa usam o próprio.
     */
    private function resolveCompany(Request $request, User $user): Company|JsonResponse
    {
        $companyId = $user->isSystemAdmin()
            ? (int) $request->integer('company_id', 0)
            : (int) $user->company_id;

        if ($companyId <= 0) {
            return response()->json(['message' => 'Informe company_id.'], 422);
        }

        $company = Company::find($companyId);
        if (! $company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        return $company;
    }

    /** Retorna um CompanyBotSetting não persistido com os defaults da empresa. */
    private function buildDefaultSettings(int $companyId): CompanyBotSetting
    {
        return new CompanyBotSetting($this->botSettingsSupport->defaultBotSettingsPayload($companyId));
    }
}

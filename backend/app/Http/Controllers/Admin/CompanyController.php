<?php

declare(strict_types=1);


namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Company\DestroyAdminCompanyAction;
use App\Actions\Admin\Company\ListAdminCompaniesAction;
use App\Actions\Admin\Company\ShowAdminCompanyAction;
use App\Actions\Admin\Company\StoreAdminCompanyAction;
use App\Actions\Admin\Company\UpdateAdminCompanyAction;
use App\Actions\Admin\Company\UpdateAdminCompanyBotSettingsAction;
use App\Actions\Admin\Company\ValidateAdminCompanyIxcAction;
use App\Actions\Admin\Company\ValidateAdminCompanyWhatsAppAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyBotSettingsRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Http\Requests\Admin\ValidateCompanyIxcRequest;
use App\Http\Requests\Admin\ValidateCompanyWhatsAppRequest;
use App\Models\Company;
use App\Services\Admin\CompanyMetricsService;
use App\Services\Admin\CompanyOwnershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function __construct(
        private readonly ListAdminCompaniesAction $listCompaniesAction,
        private readonly ShowAdminCompanyAction $showCompanyAction,
        private readonly StoreAdminCompanyAction $storeCompanyAction,
        private readonly UpdateAdminCompanyAction $updateCompanyAction,
        private readonly UpdateAdminCompanyBotSettingsAction $updateCompanyBotSettingsAction,
        private readonly DestroyAdminCompanyAction $destroyCompanyAction,
        private readonly ValidateAdminCompanyWhatsAppAction $validateAdminCompanyWhatsAppAction,
        private readonly ValidateAdminCompanyIxcAction $validateAdminCompanyIxcAction,
        private readonly CompanyOwnershipService $companyOwnership,
        private readonly CompanyMetricsService $companyMetrics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listCompaniesAction->handle($request));
    }

    private function denyIfNotOwned(Request $request, Company $company): ?JsonResponse
    {
        if (! $this->companyOwnership->canAccessCompany($request->user(), $company)) {
            return $this->errorResponse('Acesso negado para esta empresa.', 'access_denied', 403);
        }

        return null;
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

        return response()->json($this->showCompanyAction->handle($company));
    }

    public function updateBotSettings(UpdateCompanyBotSettingsRequest $request, Company $company): JsonResponse
    {
        if ($request->user()?->isSystemAdmin()) {
            return $this->errorResponse('Superadmin nao pode editar empresas diretamente.', 'forbidden', 403);
        }

        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

        return response()->json($this->updateCompanyBotSettingsAction->handle($request, $company));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $result = $this->storeCompanyAction->handle($request);

        return $result->toResponse();
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        if ($request->user()?->isSystemAdmin()) {
            return $this->errorResponse('Superadmin nao pode editar empresas diretamente.', 'forbidden', 403);
        }

        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

        $result = $this->updateCompanyAction->handle($request, $company);

        return $result->toResponse();
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        if ($request->user()?->isSystemAdmin()) {
            return $this->errorResponse('Superadmin nao pode excluir empresas diretamente.', 'forbidden', 403);
        }

        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

        return response()->json($this->destroyCompanyAction->handle($request, $company));
    }

    /** Testa as credenciais do WhatsApp da empresa contra a API da Meta. */
    public function validateWhatsApp(ValidateCompanyWhatsAppRequest $request, Company $company): JsonResponse
    {
        if ($request->user()?->isSystemAdmin()) {
            return $this->errorResponse('Superadmin nao pode editar empresas diretamente.', 'forbidden', 403);
        }

        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

        $result = $this->validateAdminCompanyWhatsAppAction->handle($request, $company);

        return $result->toResponse();
    }

    public function metrics(Request $request, Company $company): JsonResponse
    {
        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

        return response()->json([
            'authenticated' => true,
            'metrics' => $this->companyMetrics->build($company),
        ]);
    }

    public function validateIxc(ValidateCompanyIxcRequest $request, Company $company): JsonResponse
    {
        if ($request->user()?->isSystemAdmin()) {
            return $this->errorResponse('Superadmin nao pode editar empresas diretamente.', 'forbidden', 403);
        }

        if ($denied = $this->denyIfNotOwned($request, $company)) {
            return $denied;
        }

        $result = $this->validateAdminCompanyIxcAction->handle($request, $company);

        return $result->toResponse();
    }
}

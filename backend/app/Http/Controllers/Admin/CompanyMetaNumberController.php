<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyMetaNumberRequest;
use App\Http\Requests\Admin\UpdateCompanyMetaNumberRequest;
use App\Models\Company;
use App\Models\CompanyMetaNumber;
use App\Services\Company\CompanyMetaNumberService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CompanyMetaNumberController extends Controller
{
    public function __construct(
        private readonly CompanyMetaNumberService $companyMetaNumberService,
    ) {}

    public function index(Request $request, Company $company): JsonResponse
    {
        if (! $this->canManageCompany($request, $company)) {
            return $this->errorResponse('FORBIDDEN_SCOPE', 'FORBIDDEN_SCOPE', 403);
        }

        $items = CompanyMetaNumber::query()
            ->where('company_id', (int) $company->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get();

        return response()->json(['items' => $items]);
    }

    public function store(StoreCompanyMetaNumberRequest $request, Company $company): JsonResponse
    {
        try {
            $item = $this->companyMetaNumberService->createNumber((int) $company->id, $request->validated(), $request->user());
            return response()->json(['item' => $item], 201);
        } catch (AuthorizationException) {
            return $this->errorResponse('FORBIDDEN_SCOPE', 'FORBIDDEN_SCOPE', 403);
        } catch (RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), $exception->getMessage(), 422);
        }
    }

    public function update(UpdateCompanyMetaNumberRequest $request, Company $company, int $numberId): JsonResponse
    {
        try {
            $item = $this->companyMetaNumberService->updateNumber((int) $company->id, $numberId, $request->validated(), $request->user());
            return response()->json(['item' => $item]);
        } catch (AuthorizationException) {
            return $this->errorResponse('FORBIDDEN_SCOPE', 'FORBIDDEN_SCOPE', 403);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('META_NUMBER_NOT_FOUND', 'META_NUMBER_NOT_FOUND', 404);
        } catch (RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), $exception->getMessage(), 422);
        }
    }

    public function setPrimary(Request $request, Company $company, int $numberId): JsonResponse
    {
        try {
            $item = $this->companyMetaNumberService->setPrimary((int) $company->id, $numberId, $request->user());
            return response()->json(['item' => $item]);
        } catch (AuthorizationException) {
            return $this->errorResponse('FORBIDDEN_SCOPE', 'FORBIDDEN_SCOPE', 403);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('META_NUMBER_NOT_FOUND', 'META_NUMBER_NOT_FOUND', 404);
        }
    }

    public function destroy(Request $request, Company $company, int $numberId): JsonResponse
    {
        try {
            $strategy = (string) $request->input('strategy', 'deactivate');
            $this->companyMetaNumberService->deactivateOrRemove((int) $company->id, $numberId, $request->user(), $strategy);
            return response()->json(['ok' => true]);
        } catch (AuthorizationException) {
            return $this->errorResponse('FORBIDDEN_SCOPE', 'FORBIDDEN_SCOPE', 403);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('META_NUMBER_NOT_FOUND', 'META_NUMBER_NOT_FOUND', 404);
        }
    }

    private function canManageCompany(Request $request, Company $company): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        if ($user->isSystemAdmin()) {
            return true;
        }

        return $user->isResellerAdmin()
            && (int) ($user->reseller_id ?? 0) > 0
            && (int) $company->reseller_id === (int) $user->reseller_id;
    }
}


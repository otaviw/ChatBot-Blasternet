<?php

declare(strict_types=1);


namespace App\Actions\Admin\Company;

use App\Models\Company;
use App\Services\Admin\CompanyOwnershipService;
use Illuminate\Http\Request;

class ListAdminCompaniesAction
{
    public function __construct(
        private readonly CompanyOwnershipService $companyOwnership
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request): array
    {
        $resellerId = $this->companyOwnership->resolveResellerId($request->user());

        $companiesQuery = Company::with(['botSetting'])
            ->withCount('conversations')
            ->forReseller($resellerId);

        if ($request->user()?->isSystemAdmin()) {
            $companiesQuery->whereNotNull('reseller_id');
        }

        $perPage = min(100, max(10, $request->integer('per_page', 50)));

        $paginator = $companiesQuery
            ->orderBy('name')
            ->paginate($perPage);

        return [
            'authenticated' => true,
            'role'          => 'admin',
            'companies'     => $paginator->items(),
            'pagination'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ];
    }
}


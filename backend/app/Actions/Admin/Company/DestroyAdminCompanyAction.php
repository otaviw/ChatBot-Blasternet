<?php

declare(strict_types=1);


namespace App\Actions\Admin\Company;

use App\Models\Company;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

class DestroyAdminCompanyAction
{
    public function __construct(
        private readonly AuditLogService $auditLog
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, Company $company): array
    {
        $companyId = $company->id;
        $companyName = $company->name;

        $company->delete();

        $this->auditLog->record(
            $request,
            'admin.company.deleted',
            $companyId,
            [
                'name' => $companyName,
            ]
        );

        return [
            'ok' => true,
        ];
    }
}


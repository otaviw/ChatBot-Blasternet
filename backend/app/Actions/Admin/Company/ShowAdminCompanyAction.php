<?php

namespace App\Actions\Admin\Company;

use App\Models\Company;

class ShowAdminCompanyAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Company $company): array
    {
        $company->loadCount('conversations');
        $company->load([
            'botSetting',
        ]);

        return [
            'authenticated' => true,
            'role' => 'admin',
            'company' => $company,
        ];
    }
}


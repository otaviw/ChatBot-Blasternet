<?php

declare(strict_types=1);


namespace App\Services\Admin;

use App\Models\Company;
use App\Models\User;

class CompanyOwnershipService
{
    public function resolveResellerId(?User $user): ?int
    {
        if (! $user) {
            return -1;
        }

        if ($user->isSystemAdmin()) {
            return null;
        }

        if ($user->isResellerAdmin()) {
            $resellerId = (int) ($user->reseller_id ?? $user->company?->reseller_id ?? 0);

            return $resellerId > 0 ? $resellerId : -1;
        }

        return -1;
    }

    public function canAccessCompany(?User $user, Company $company): bool
    {
        if (! $user) {
            return false;
        }

        // System admin nao acessa empresa sem reseller vinculado.
        // Empresas orfas sao consideradas invalidas e nao devem ser editadas por nenhum perfil.
        if ($user->isSystemAdmin() && ! $company->reseller_id) {
            return false;
        }

        $resellerId = $this->resolveResellerId($user);
        if ($resellerId === null) {
            return true;
        }

        return (int) $company->reseller_id === $resellerId;
    }
}

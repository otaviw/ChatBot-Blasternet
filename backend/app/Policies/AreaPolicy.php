<?php

namespace App\Policies;

use App\Models\Area;
use App\Models\User;

class AreaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCompanyUser();
    }

    public function create(User $user): bool
    {
        return $user->isCompanyAdmin() || $user->isSystemAdmin();
    }

    public function delete(User $user, Area $area): bool
    {
        return ($user->isCompanyAdmin() || $user->isSystemAdmin())
            && (int) $user->company_id === (int) $area->company_id;
    }

    public function view(User $user, Area $area): bool
    {
        return $user->isCompanyUser() && (int) $user->company_id === (int) $area->company_id;
    }
}


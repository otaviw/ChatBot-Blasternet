<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCompanyUser() && ! empty($user->company_id);
    }

    public function view(User $user, Tag $tag): bool
    {
        return $user->isCompanyUser() && (int) $user->company_id === (int) $tag->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isCompanyUser() && ! empty($user->company_id);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->isCompanyUser() && (int) $user->company_id === (int) $tag->company_id;
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->isCompanyUser() && (int) $user->company_id === (int) $tag->company_id;
    }
}

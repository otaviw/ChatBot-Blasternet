<?php

declare(strict_types=1);


namespace App\Policies;

use App\Models\QuickReply;
use App\Models\User;

class QuickReplyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCompanyUser() && ! empty($user->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isCompanyUser() && ! empty($user->company_id);
    }

    public function update(User $user, QuickReply $quickReply): bool
    {
        return $user->isCompanyUser() && (int) $user->company_id === (int) $quickReply->company_id;
    }

    public function delete(User $user, QuickReply $quickReply): bool
    {
        return $user->isCompanyUser() && (int) $user->company_id === (int) $quickReply->company_id;
    }
}

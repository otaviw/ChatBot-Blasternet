<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->belongsToCompany($user, $conversation);
    }

    public function transfer(User $user, Conversation $conversation): bool
    {
        return $this->belongsToCompany($user, $conversation);
    }

    public function viewTransfers(User $user, Conversation $conversation): bool
    {
        return $this->belongsToCompany($user, $conversation);
    }

    private function belongsToCompany(User $user, Conversation $conversation): bool
    {
        return $user->isCompanyUser() && (int) $user->company_id === (int) $conversation->company_id;
    }
}


<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\ResellerAiCompanyPermission;

class ResellerAiCompanyPermissionService
{
    public function isCompanyAllowed(?int $resellerId, int $companyId): bool
    {
        if (($resellerId ?? 0) <= 0 || $companyId <= 0) {
            return false;
        }

        return ResellerAiCompanyPermission::query()
            ->where('reseller_id', (int) $resellerId)
            ->where('company_id', $companyId)
            ->where('ai_chatbot_allowed', true)
            ->exists();
    }
}

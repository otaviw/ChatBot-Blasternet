<?php

namespace App\Services\Ai;

use App\Models\AiCompanyKnowledge;
use Illuminate\Support\Collection;

class AiCompanyKnowledgeService
{
    /**
     * @return Collection<int, AiCompanyKnowledge>
     */
    public function getActiveForCompany(int $companyId, int $limit = 5): Collection
    {
        if ($companyId <= 0) {
            return collect();
        }

        $safeLimit = min(50, max(1, $limit));

        return AiCompanyKnowledge::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($safeLimit)
            ->get(['title', 'content']);
    }
}


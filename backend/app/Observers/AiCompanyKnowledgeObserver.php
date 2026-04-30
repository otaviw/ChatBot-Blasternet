<?php

declare(strict_types=1);


namespace App\Observers;

use App\Jobs\IndexKnowledgeItemJob;
use App\Models\AiCompanyKnowledge;
use Illuminate\Support\Facades\DB;

/**
 * Triggers async re-indexing of knowledge items whenever they are created,
 * updated, or deleted, so the RAG embedding index stays in sync.
 *
 * The job is a no-op when AI_RAG_ENABLED=false or AI_RAG_EMBEDDING_MODEL
 * is not set, so registering this observer is always safe.
 */
class AiCompanyKnowledgeObserver
{
    /**
     * Runs after create or update — always after the DB transaction commits.
     */
    public function saved(AiCompanyKnowledge $item): void
    {
        // Mark pending via raw query to avoid re-triggering this observer
        DB::table('ai_company_knowledge')
            ->where('id', (int) $item->id)
            ->update([
                'indexing_status' => AiCompanyKnowledge::INDEXING_PENDING,
                'indexed_at'      => null,
            ]);

        IndexKnowledgeItemJob::dispatch((int) $item->id)->afterCommit();
    }

    /**
     * Runs after delete — chunks are removed by the job (cascade also handles it).
     * We still dispatch so any edge-case soft-delete scenarios are covered.
     */
    public function deleted(AiCompanyKnowledge $item): void
    {
        IndexKnowledgeItemJob::dispatch((int) $item->id)->afterCommit();
    }
}

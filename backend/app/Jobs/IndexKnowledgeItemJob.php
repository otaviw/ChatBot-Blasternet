<?php

declare(strict_types=1);


namespace App\Jobs;

use App\Models\AiCompanyKnowledge;
use App\Models\AiKnowledgeChunk;
use App\Services\Ai\Rag\AiEmbeddingService;
use App\Services\Ai\Rag\AiKnowledgeChunkerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Indexes (or re-indexes) a single knowledge item into embedding chunks.
 *
 * Dispatched by AiCompanyKnowledgeObserver on saved/deleted.
 * Safe to dispatch multiple times — always deletes and recreates chunks.
 *
 * Will silently no-op when:
 *  - The item no longer exists
 *  - The item is inactive
 *  - AI_RAG_EMBEDDING_MODEL is not configured
 */
class IndexKnowledgeItemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> Seconds before each retry: 30s, 2min */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $knowledgeItemId) {}

    public function handle(AiKnowledgeChunkerService $chunker, AiEmbeddingService $embedder): void
    {
        $embeddingModel = trim((string) config('ai.rag.embedding_model', ''));
        if ($embeddingModel === '') {
            // RAG not configured — nothing to index
            return;
        }

        // Load fresh from DB (model may have changed since dispatch)
        $item = AiCompanyKnowledge::find($this->knowledgeItemId);

        // Delete old chunks regardless — handles deactivation and deletion
        AiKnowledgeChunk::where('ai_knowledge_item_id', $this->knowledgeItemId)->delete();

        if ($item === null) {
            // Item was hard-deleted; cascade would have handled chunks, but
            // we handle it here as a safety net for soft-delete scenarios.
            return;
        }

        if (! (bool) $item->is_active) {
            // Item deactivated — chunks removed above, nothing to re-index
            DB::table('ai_company_knowledge')
                ->where('id', (int) $item->id)
                ->update(['indexing_status' => AiCompanyKnowledge::INDEXING_INDEXED, 'indexed_at' => now()]);

            return;
        }

        $content = trim((string) $item->content);
        if ($content === '') {
            return;
        }

        $maxChunkSize = (int) config('ai.rag.chunk_size', 400);
        $chunkOverlap = (int) config('ai.rag.chunk_overlap', 50);

        $rawChunks = $chunker->chunk($content, $maxChunkSize, $chunkOverlap);

        foreach ($rawChunks as $index => $chunkText) {
            $chunkText = trim($chunkText);
            if ($chunkText === '') {
                continue;
            }

            $embedding = $embedder->embed($chunkText);

            AiKnowledgeChunk::create([
                'ai_knowledge_item_id' => (int) $item->id,
                'company_id' => (int) $item->company_id,
                'title' => (string) $item->title,
                'chunk_content' => $chunkText,
                'chunk_index' => $index,
                'embedding' => $embedding !== null ? json_encode($embedding) : null,
                'embedding_model' => $embedding !== null ? $embeddingModel : null,
            ]);
        }

        // Mark item as indexed
        DB::table('ai_company_knowledge')
            ->where('id', (int) $item->id)
            ->update([
                'indexing_status' => AiCompanyKnowledge::INDEXING_INDEXED,
                'indexed_at'      => now(),
            ]);

        Log::debug('ai.rag.indexed', [
            'knowledge_item_id' => (int) $item->id,
            'company_id' => (int) $item->company_id,
            'chunks' => count($rawChunks),
            'model' => $embeddingModel,
        ]);
    }

    /**
     * Called when the job fails after exhausting all retry attempts.
     * Marks the knowledge item as failed so the UI can surface the error.
     */
    public function failed(?\Throwable $exception): void
    {
        DB::table('ai_company_knowledge')
            ->where('id', $this->knowledgeItemId)
            ->update(['indexing_status' => AiCompanyKnowledge::INDEXING_FAILED]);

        Log::error('ai.rag.index_failed', [
            'knowledge_item_id' => $this->knowledgeItemId,
            'error' => $exception?->getMessage(),
        ]);
    }
}

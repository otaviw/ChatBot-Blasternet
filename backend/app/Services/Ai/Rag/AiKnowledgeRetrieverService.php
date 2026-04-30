<?php

declare(strict_types=1);


namespace App\Services\Ai\Rag;

use App\Models\AiKnowledgeChunk;
use App\Services\Ai\AiCompanyKnowledgeService;
use Illuminate\Support\Facades\Log;

/**
 * Retrieves the most relevant knowledge chunks for a given query.
 *
 * When AI_RAG_ENABLED=true and an embedding model is configured:
 *   - Embeds the query with AiEmbeddingService
 *   - Computes cosine similarity against all indexed chunks in PHP
 *   - Returns top-k chunks deduplicated by source knowledge item
 *
 * Falls back to static (recency-ordered) retrieval transparently when:
 *   - RAG is disabled (AI_RAG_ENABLED=false, the default)
 *   - No embedding model is configured
 *   - The embedding call fails (Ollama unavailable)
 *   - No chunks have been indexed yet
 *
 * Return shape: list<array{title: string, content: string, score: float|null}>
 */
class AiKnowledgeRetrieverService
{
    public function __construct(
        private readonly AiEmbeddingService $embeddingService,
        private readonly AiCompanyKnowledgeService $knowledgeService
    ) {}

    /**
     * Retrieve the most relevant knowledge items/chunks for a query.
     *
     * @param  string|null  $query  The user's current message (null → static fallback)
     * @return list<array{title: string, content: string, score: float|null}>
     */
    public function retrieve(int $companyId, ?string $query, int $topK = 3): array
    {
        if ($companyId <= 0) {
            return [];
        }

        $ragEnabled = (bool) config('ai.rag.enabled', false);

        if (! $ragEnabled || $query === null || trim($query) === '') {
            return $this->staticItems($companyId, $topK);
        }

        $queryEmbedding = $this->embeddingService->embed($query);
        if ($queryEmbedding === null) {
            Log::debug('ai.rag.no_embedding', ['company_id' => $companyId]);

            return $this->staticItems($companyId, $topK);
        }

        $chunks = AiKnowledgeChunk::query()
            ->where('company_id', $companyId)
            ->whereNotNull('embedding')
            ->whereNotNull('embedding_model')
            ->get(['id', 'ai_knowledge_item_id', 'title', 'chunk_content', 'chunk_index', 'embedding']);

        if ($chunks->isEmpty()) {
            Log::debug('ai.rag.no_chunks_indexed', ['company_id' => $companyId]);

            return $this->staticItems($companyId, $topK);
        }

        $scored = [];

        foreach ($chunks as $index => $chunk) {
            $chunkEmbedding = json_decode((string) $chunk->embedding, true);
            if (! is_array($chunkEmbedding) || $chunkEmbedding === []) {
                continue;
            }

            $score = $this->cosineSimilarity($queryEmbedding, $chunkEmbedding);

            $scored[] = [
                'index' => $index,
                'knowledge_item_id' => (int) $chunk->ai_knowledge_item_id,
                'title' => (string) $chunk->title,
                'content' => (string) $chunk->chunk_content,
                'score' => $score,
            ];
        }

        if ($scored === []) {
            return $this->staticItems($companyId, $topK);
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $result = $this->deduplicateTopK($scored, $topK);

        return array_map(static fn (array $entry): array => [
            'title' => $entry['title'],
            'content' => $entry['content'],
            'score' => $entry['score'],
        ], $result);
    }

    /**
     * Static fallback: return most-recently-updated active items.
     *
     * @return list<array{title: string, content: string, score: null}>
     */
    public function staticItems(int $companyId, int $limit): array
    {
        return $this->knowledgeService
            ->getActiveForCompany($companyId, $limit)
            ->map(static fn ($item): array => [
                'title' => (string) $item->title,
                'content' => (string) $item->content,
                'score' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * Take top-k entries, preferring one chunk per unique knowledge item.
     * If fewer items than k exist, fill remaining from next-best chunks.
     *
     * @param  list<array{index:int, knowledge_item_id:int, title:string, content:string, score:float}>  $sorted  Sorted by score desc
     * @return list<array{index:int, knowledge_item_id:int, title:string, content:string, score:float}>
     */
    private function deduplicateTopK(array $sorted, int $topK): array
    {
        $result = [];
        $pickedEntryIndexes = [];
        $seenItemIds = [];

        // First pass: best chunk per unique knowledge item
        foreach ($sorted as $i => $entry) {
            if (count($result) >= $topK) {
                break;
            }

            $itemId = $entry['knowledge_item_id'];
            if (! isset($seenItemIds[$itemId])) {
                $seenItemIds[$itemId] = true;
                $pickedEntryIndexes[$i] = true;
                $result[] = $entry;
            }
        }

        // Second pass: fill remaining slots with any unchosen chunk
        if (count($result) < $topK) {
            foreach ($sorted as $i => $entry) {
                if (count($result) >= $topK) {
                    break;
                }

                if (! isset($pickedEntryIndexes[$i])) {
                    $result[] = $entry;
                }
            }
        }

        return $result;
    }

    /**
     * Cosine similarity between two float vectors.
     * Returns 0.0 if either vector is zero or dimensions differ.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $count = min(count($a), count($b));
        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $ai = (float) $a[$i];
            $bi = (float) $b[$i];
            $dot += $ai * $bi;
            $normA += $ai * $ai;
            $normB += $bi * $bi;
        }

        $denominator = sqrt($normA) * sqrt($normB);
        if ($denominator < 1e-10) {
            return 0.0;
        }

        return (float) ($dot / $denominator);
    }
}

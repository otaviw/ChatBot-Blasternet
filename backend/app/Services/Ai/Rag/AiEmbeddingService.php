<?php

namespace App\Services\Ai\Rag;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates text embeddings via the Ollama embeddings API.
 *
 * Embedding model is configured separately from the chat model:
 *   AI_RAG_EMBEDDING_MODEL=nomic-embed-text
 *
 * When the embedding model is not configured or the Ollama server is
 * unavailable, all methods return null/empty so callers can fall back
 * to static knowledge retrieval without crashing.
 */
class AiEmbeddingService
{
    /**
     * Generate a float vector for the given text.
     * Returns null if the model is not configured or the API call fails.
     *
     * @return list<float>|null
     */
    public function embed(string $text): ?array
    {
        $model = $this->resolveModel();
        if ($model === null) {
            return null;
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $url = $this->resolveUrl();
        $timeoutSeconds = (int) config('ai.rag.embedding_timeout_seconds', 15);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout($timeoutSeconds)
                ->connectTimeout(min($timeoutSeconds, 5))
                ->post($url, [
                    'model' => $model,
                    'prompt' => $text,
                ]);
        } catch (Throwable $exception) {
            Log::warning('ai.rag.embedding_request_failed', [
                'url' => $url,
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('ai.rag.embedding_response_error', [
                'url' => $url,
                'model' => $model,
                'status' => $response->status(),
            ]);

            return null;
        }

        $embedding = $response->json('embedding');
        if (! is_array($embedding) || $embedding === []) {
            Log::warning('ai.rag.embedding_empty_response', [
                'url' => $url,
                'model' => $model,
            ]);

            return null;
        }

        return array_map('floatval', $embedding);
    }

    /**
     * Whether the embedding service is configured and can generate embeddings.
     */
    public function isAvailable(): bool
    {
        return $this->resolveModel() !== null;
    }

    private function resolveModel(): ?string
    {
        $model = trim((string) config('ai.rag.embedding_model', ''));

        return $model !== '' ? $model : null;
    }

    private function resolveUrl(): string
    {
        $baseUrl = rtrim((string) config('ai.providers.ollama.base_url', 'http://127.0.0.1:11434'), '/');
        $embeddingsPath = ltrim((string) config('ai.rag.embedding_path', '/api/embeddings'), '/');

        return $baseUrl.'/'.$embeddingsPath;
    }
}

<?php

use App\Services\Ai\Providers\NullAiProvider;
use App\Services\Ai\Providers\OllamaAiProvider;
use App\Services\Ai\Providers\AnthropicAiProvider;
use App\Services\Ai\Providers\TestAiProvider;

return [
    /*
    |--------------------------------------------------------------------------
    | Provider padrao
    |--------------------------------------------------------------------------
    |
    | Mantemos "provider" por compatibilidade com as chamadas atuais.
    | "default_provider" prepara o caminho para configurações mais explícitas.
    |
    */
    'default_provider' => env('AI_PROVIDER', 'test'),

    'provider' => env('AI_PROVIDER', 'test'),

    /*
    |--------------------------------------------------------------------------
    | Provider de fallback
    |--------------------------------------------------------------------------
    |
    | Utilizado quando o provider configurado não está disponível/registrado.
    |
    */
    'fallback_provider' => env('AI_FALLBACK_PROVIDER', 'null'),

    'ollama_fallback_provider' => env('AI_OLLAMA_FALLBACK_PROVIDER', 'anthropic'),

    'model' => env('AI_MODEL', 'test-model'),

    'system_prompt' => env('AI_SYSTEM_PROMPT'),

    'temperature' => env('AI_TEMPERATURE'),

    'max_response_tokens' => env('AI_MAX_RESPONSE_TOKENS'),

    'history_messages_limit' => (int) env('AI_HISTORY_MESSAGES_LIMIT', 20),

    'request_timeout_ms' => (int) env('AI_REQUEST_TIMEOUT_MS', 30000),

    'chatbot_request_timeout_ms' => (int) env('AI_CHATBOT_REQUEST_TIMEOUT_MS', 12000),

    'chatbot_feature_enabled' => (bool) env('AI_CHATBOT_FEATURE_ENABLED', false),

    'circuit_breaker' => [
        'enabled' => (bool) env('AI_CIRCUIT_BREAKER_ENABLED', true),
        'failure_threshold' => (int) env('AI_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 5),
        'cooldown_seconds' => (int) env('AI_CIRCUIT_BREAKER_COOLDOWN_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registro de providers
    |--------------------------------------------------------------------------
    |
    | Permite adicionar provider real no futuro com mínimo retrabalho no
    | resolver, mantendo a camada desacoplada de implementação específica.
    |
    */
    'provider_classes' => [
        'anthropic' => AnthropicAiProvider::class,
        'ollama' => OllamaAiProvider::class,
        'test' => TestAiProvider::class,
        'null' => NullAiProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Guardrails de segurança
    |--------------------------------------------------------------------------
    |
    | forbidden_words — lista de palavras/frases proibidas (array ou string
    |   separada por vírgula via env AI_SAFETY_FORBIDDEN_WORDS).
    |   Vazio por padrão para evitar falsos positivos.
    |
    | Exemplo de .env:
    |   AI_SAFETY_FORBIDDEN_WORDS="palavra1,frase proibida"
    |
    */
    /*
    |--------------------------------------------------------------------------
    | RAG — Retrieval-Augmented Generation
    |--------------------------------------------------------------------------
    |
    | When AI_RAG_ENABLED=true, each AI call retrieves the most semantically
    | relevant knowledge chunks via cosine similarity instead of injecting the
    | top-5 most-recently-updated items.
    |
    | AI_RAG_EMBEDDING_MODEL — the Ollama model used to generate embeddings
    |   (e.g. "nomic-embed-text"). MUST be different from the chat model.
    |   When empty, RAG is effectively disabled even if AI_RAG_ENABLED=true.
    |
    | AI_RAG_TOP_K         — number of chunks to inject into each prompt (default 3)
    | AI_RAG_CHUNK_SIZE    — max characters per chunk (default 400)
    | AI_RAG_CHUNK_OVERLAP — overlap chars between adjacent chunks (default 50)
    |
    | Embedding path (rarely needs changing):
    |   AI_RAG_EMBEDDING_PATH=/api/embeddings  (Ollama legacy; new: /api/embed)
    |
    | Example .env for production:
    |   AI_RAG_ENABLED=true
    |   AI_RAG_EMBEDDING_MODEL=nomic-embed-text
    |   AI_RAG_TOP_K=3
    |
    */
    'rag' => [
        'enabled' => (bool) env('AI_RAG_ENABLED', false),
        'embedding_model' => env('AI_RAG_EMBEDDING_MODEL', ''),
        'embedding_path' => env('AI_RAG_EMBEDDING_PATH', '/api/embeddings'),
        'embedding_timeout_seconds' => (int) env('AI_RAG_EMBEDDING_TIMEOUT_SECONDS', 15),
        'top_k' => (int) env('AI_RAG_TOP_K', 3),
        'chunk_size' => (int) env('AI_RAG_CHUNK_SIZE', 400),
        'chunk_overlap' => (int) env('AI_RAG_CHUNK_OVERLAP', 50),
    ],

    'safety' => [
        'forbidden_words' => array_filter(
            array_map('trim', explode(',', (string) env('AI_SAFETY_FORBIDDEN_WORDS', '')))
        ),
    ],

    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest'),
            'base_url' => env('AI_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'messages_path' => env('AI_ANTHROPIC_MESSAGES_PATH', '/v1/messages'),
            'version' => env('AI_ANTHROPIC_VERSION', '2023-06-01'),
            'max_response_tokens' => (int) env('AI_ANTHROPIC_MAX_RESPONSE_TOKENS', 1024),
        ],

        'ollama' => [
            'base_url' => env('AI_OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
            'chat_path' => env('AI_OLLAMA_CHAT_PATH', '/api/chat'),
        ],

        'test' => [
            'reply_prefix' => env('AI_TEST_REPLY_PREFIX', '[AI TEST]'),
        ],

        'null' => [
            'fallback_message' => env('AI_NULL_FALLBACK_MESSAGE', 'Servico de IA indisponivel no momento.'),
        ],
    ],
];

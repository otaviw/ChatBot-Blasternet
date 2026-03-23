<?php

use App\Services\Ai\Providers\NullAiProvider;
use App\Services\Ai\Providers\OllamaAiProvider;
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

    'model' => env('AI_MODEL', 'test-model'),

    'system_prompt' => env('AI_SYSTEM_PROMPT'),

    'temperature' => env('AI_TEMPERATURE'),

    'max_response_tokens' => env('AI_MAX_RESPONSE_TOKENS'),

    'history_messages_limit' => (int) env('AI_HISTORY_MESSAGES_LIMIT', 20),

    'request_timeout_ms' => (int) env('AI_REQUEST_TIMEOUT_MS', 30000),

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
        'ollama' => OllamaAiProvider::class,
        'test' => TestAiProvider::class,
        'null' => NullAiProvider::class,
    ],

    'providers' => [
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

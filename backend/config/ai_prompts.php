<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ambiente de prompts
    |--------------------------------------------------------------------------
    |
    | Aceita "dev" ou "prod". Quando vazio, o resolver mapeia automaticamente
    | APP_ENV local/testing/development -> dev, demais -> prod.
    |
    */
    'environment' => env('AI_PROMPT_ENV', ''),

    /*
    |--------------------------------------------------------------------------
    | Logs e histórico
    |--------------------------------------------------------------------------
    */
    'logs_enabled' => (bool) env('AI_PROMPT_LOGS_ENABLED', true),
    'history_enabled' => (bool) env('AI_PROMPT_HISTORY_ENABLED', true),
    'history_preview_chars' => (int) env('AI_PROMPT_HISTORY_PREVIEW_CHARS', 220),

    /*
    |--------------------------------------------------------------------------
    | Templates
    |--------------------------------------------------------------------------
    |
    | Estrutura:
    | - version: versão do template (versionamento)
    | - fallback: chave alternativa quando conteúdo estiver ausente
    | - environments: conteúdo por ambiente (dev/prod)
    |
    */
    'templates' => [
        'shared.default' => [
            'version' => env('AI_PROMPT_SHARED_DEFAULT_VERSION', 'v1'),
            'environments' => [
                'dev' => env('AI_PROMPT_SHARED_DEFAULT_DEV', ''),
                'prod' => env('AI_PROMPT_SHARED_DEFAULT_PROD', ''),
            ],
        ],

        'internal_chat.system' => [
            'version' => env('AI_PROMPT_INTERNAL_CHAT_VERSION', 'v1'),
            'fallback' => 'shared.default',
            'environments' => [
                'dev' => env('AI_PROMPT_INTERNAL_CHAT_DEV', ''),
                'prod' => env('AI_PROMPT_INTERNAL_CHAT_PROD', ''),
            ],
        ],

        'conversation_suggestion.system' => [
            'version' => env('AI_PROMPT_CONVERSATION_SUGGESTION_VERSION', 'v1'),
            'fallback' => 'shared.default',
            'environments' => [
                'dev' => env('AI_PROMPT_CONVERSATION_SUGGESTION_DEV', ''),
                'prod' => env('AI_PROMPT_CONVERSATION_SUGGESTION_PROD', ''),
            ],
        ],

        'sandbox.system' => [
            'version' => env('AI_PROMPT_SANDBOX_VERSION', 'v1'),
            'fallback' => 'shared.default',
            'environments' => [
                'dev' => env('AI_PROMPT_SANDBOX_DEV', ''),
                'prod' => env('AI_PROMPT_SANDBOX_PROD', ''),
            ],
        ],
    ],
];


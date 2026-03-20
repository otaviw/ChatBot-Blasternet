<?php

return [
    'provider' => env('AI_PROVIDER', 'test'),

    'model' => env('AI_MODEL', 'test-model'),

    'system_prompt' => env('AI_SYSTEM_PROMPT'),

    'temperature' => env('AI_TEMPERATURE'),

    'max_response_tokens' => env('AI_MAX_RESPONSE_TOKENS'),

    'history_messages_limit' => (int) env('AI_HISTORY_MESSAGES_LIMIT', 20),

    'request_timeout_ms' => (int) env('AI_REQUEST_TIMEOUT_MS', 30000),

    'providers' => [
        'test' => [
            'reply_prefix' => env('AI_TEST_REPLY_PREFIX', '[AI TEST]'),
        ],

        'null' => [
            'fallback_message' => env('AI_NULL_FALLBACK_MESSAGE', 'Servico de IA indisponivel no momento.'),
        ],
    ],
];

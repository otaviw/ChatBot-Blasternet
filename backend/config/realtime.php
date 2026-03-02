<?php

return [
    'enabled' => (bool) env('REALTIME_ENABLED', true),

    'jwt' => [
        'secret' => env('REALTIME_JWT_SECRET', ''),
        'issuer' => env('REALTIME_JWT_ISSUER', env('APP_URL', 'http://localhost')),
        'audience' => env('REALTIME_JWT_AUDIENCE', 'realtime'),
        'token_ttl_seconds' => (int) env('REALTIME_TOKEN_TTL_SECONDS', 120),
        'join_token_ttl_seconds' => (int) env('REALTIME_JOIN_TOKEN_TTL_SECONDS', 45),
    ],

    'redis' => [
        'channel' => env('REALTIME_REDIS_CHANNEL', 'realtime.events'),
    ],

    'fallback' => [
        'internal_emit_url' => env('REALTIME_INTERNAL_EMIT_URL', ''),
        'internal_key' => env('REALTIME_INTERNAL_KEY', ''),
        'timeout_ms' => (int) env('REALTIME_INTERNAL_TIMEOUT_MS', 800),
    ],

    'publish' => [
        // supported: after_response, queue, sync
        'mode' => env('REALTIME_PUBLISH_MODE', 'after_response'),
        'queue' => env('REALTIME_PUBLISH_QUEUE', 'realtime'),
    ],

    'client' => [
        'url' => env('REALTIME_CLIENT_URL', 'http://localhost:8081'),
    ],
];

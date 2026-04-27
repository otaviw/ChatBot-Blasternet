<?php

return [
    'login' => (int) env('RATE_LIMIT_LOGIN', 5),
    'password_reset' => (int) env('RATE_LIMIT_PASSWORD_RESET', 5),
    'webhook_inbound' => (int) env('RATE_LIMIT_WEBHOOK_INBOUND', 500),
    'api_global' => (int) env('RATE_LIMIT_API_GLOBAL', 600),
    'bot_write' => (int) env('RATE_LIMIT_BOT_WRITE', 60),
    'simulation' => (int) env('RATE_LIMIT_SIMULATION', 30),
    'inbox_read' => (int) env('RATE_LIMIT_INBOX_READ', 180),
    'conversation_search' => (int) env('RATE_LIMIT_CONVERSATION_SEARCH', 30),
    'realtime_token' => (int) env('RATE_LIMIT_REALTIME_TOKEN', 30),
    'realtime_join' => (int) env('RATE_LIMIT_REALTIME_JOIN', 120),
];

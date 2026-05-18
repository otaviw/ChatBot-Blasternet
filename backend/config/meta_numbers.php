<?php

declare(strict_types=1);

return [
    // Deploy 2: exigir seleção explícita quando houver mais de um número ativo.
    'require_selection_on_new_conversation' => (bool) env('META_NUMBERS_REQUIRE_SELECTION_ON_NEW_CONVERSATION', false),

    // Deploy 3: obrigar resolução por contato em campanhas.
    'enforce_campaign_contact_number' => (bool) env('META_NUMBERS_ENFORCE_CAMPAIGN_CONTACT_NUMBER', false),

    // Monitoramento operacional.
    'monitoring_enabled' => (bool) env('META_NUMBERS_MONITORING_ENABLED', true),
    'post_go_live_days' => (int) env('META_NUMBERS_POST_GO_LIVE_DAYS', 7),

    // Backfill.
    'backfill_chunk_size' => (int) env('META_NUMBERS_BACKFILL_CHUNK_SIZE', 500),
];


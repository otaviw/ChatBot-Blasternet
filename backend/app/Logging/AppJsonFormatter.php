<?php

declare(strict_types=1);


namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * Formatter JSON estruturado para produção.
 *
 * Saída (uma linha JSON por entrada — NDJSON):
 * {
 *   "timestamp": "2026-04-15T14:32:00.000000Z",
 *   "level":     "error",
 *   "message":   "Unhandled exception.",
 *   "context":   { "exception": "...", "user_id": 42, ... },
 *   "service":   "Blasternet",
 *   "env":       "production",
 *   "request_id":"550e8400-e29b-41d4-a716-446655440000"
 * }
 *
 * Campos do Monolog que NÃO são incluídos na saída final:
 *  - channel   : redundante — todas as entradas vêm do mesmo canal configurado
 *  - extra     : promovido para o topo (service, env, request_id) e descartado
 *  - formatted : artefato interno do Monolog
 */
class AppJsonFormatter extends JsonFormatter
{
    public function __construct()
    {
        parent::__construct(self::BATCH_MODE_NEWLINES, appendNewline: true);
    }

    public function format(LogRecord $record): string
    {
        $extra = $record->extra;

        $data = [
            'timestamp'  => $record->datetime->format('Y-m-d\TH:i:s.u\Z'),
            'level'      => strtolower($record->level->getName()),
            'message'    => $record->message,
            'context'    => $record->context ?: (object) [], // {} em vez de [] quando vazio
            'service'    => $extra['service']    ?? config('app.name', 'laravel'),
            'env'        => $extra['env']        ?? config('app.env', 'production'),
            'request_id' => $extra['request_id'] ?? null,
            'user_id'    => $extra['user_id']    ?? null,
            'company_id' => $extra['company_id'] ?? null,
        ];

        return $this->toJson($data, true) . "\n";
    }
}

<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Illuminate\Support\Str;

/**
 * Monolog processor: injeta campos de contexto fixos em todos os log records.
 *
 * Campos adicionados em $record->extra:
 *  - request_id : UUID gerado uma vez por processo (estável durante toda a request)
 *  - service    : nome da aplicação (config app.name)
 *  - env        : ambiente (config app.env)
 *  - user_id    : ID do usuário autenticado (null em jobs/artisan)
 *  - company_id : ID da company do usuário (null para system admins e jobs)
 *
 * Esses campos terminam no JSON final porque AppJsonFormatter os promove
 * de extra para o topo do objeto JSON.
 */
class AddRequestContext implements ProcessorInterface
{
    /** UUID único por processo (mesma request = mesmo ID). */
    private static ?string $requestId = null;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: array_merge($record->extra, [
            'request_id' => static::getRequestId(),
            'service'    => config('app.name', 'laravel'),
            'env'        => config('app.env', 'production'),
            'user_id'    => static::resolveUserId(),
            'company_id' => static::resolveCompanyId(),
        ]));
    }

    private static function getRequestId(): string
    {
        if (static::$requestId === null) {
            // Preferência: X-Request-ID enviado pelo load balancer/proxy
            $header = request()->header('X-Request-ID');
            static::$requestId = (is_string($header) && $header !== '')
                ? $header
                : Str::uuid()->toString();
        }

        return static::$requestId;
    }

    private static function resolveUserId(): ?int
    {
        try {
            $user = auth()->user();
            return $user ? (int) $user->id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function resolveCompanyId(): ?int
    {
        try {
            $user = auth()->user();
            $id = $user?->company_id;
            return ($id !== null && $id > 0) ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

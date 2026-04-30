<?php

declare(strict_types=1);


namespace App\Logging;

use App\Http\Middleware\RequestTrackingMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor: injeta campos de contexto fixos em todos os log records.
 *
 * Campos adicionados em $record->extra:
 *  - request_id : ID da request atual (header/attribute) ou UUID fallback
 *  - service    : nome da aplicacao (config app.name)
 *  - env        : ambiente (config app.env)
 *  - user_id    : ID do usuario autenticado (null em jobs/artisan)
 *  - company_id : ID da company do usuario (null para system admins e jobs)
 */
class AddRequestContext implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: array_merge($record->extra, [
            'request_id' => static::getRequestId(),
            'service' => config('app.name', 'laravel'),
            'env' => config('app.env', 'production'),
            'user_id' => static::resolveUserId(),
            'company_id' => static::resolveCompanyId(),
        ]));
    }

    private static function getRequestId(): string
    {
        try {
            if (app()->bound('request')) {
                /** @var Request $request */
                $request = app('request');

                $attributeId = trim((string) $request->attributes->get(RequestTrackingMiddleware::ATTRIBUTE_KEY, ''));
                if ($attributeId !== '') {
                    return $attributeId;
                }

                $headerId = trim((string) $request->header(RequestTrackingMiddleware::HEADER_NAME, ''));
                if ($headerId !== '') {
                    return $headerId;
                }
            }
        } catch (\Throwable) {
            // fallback abaixo
        }

        return (string) Str::uuid();
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

<?php

declare(strict_types=1);


namespace App\Http\Middleware;

use App\Logging\MetricsLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de métricas HTTP.
 *
 * Registra uma entrada de log por request ao final do ciclo, contendo:
 *  - method       : verbo HTTP
 *  - path         : rota normalizada (IDs substituídos por {id})
 *  - status       : HTTP status code da resposta
 *  - duration_ms  : tempo total de processamento em ms
 *  - user_id      : ID do usuário autenticado (null para anônimos)
 *  - company_id   : ID da empresa do usuário (null quando não disponível)
 *
 * O nível de log varia com o status: info (<400), warning (4xx), error (5xx).
 * Isso permite usar filtros de nível existentes para alertas sem regras extras.
 *
 * Nota: usa hrtime(true) (nanosegundos) em vez de microtime() para evitar
 * distorções causadas por ajustes de clock (NTP, leap second, etc.).
 */
class RequestMetricsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $startNs = hrtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = round((hrtime(true) - $startNs) / 1_000_000, 2);

        $user = $request->user();

        MetricsLogger::httpRequest([
            'method'      => $request->method(),
            'path'        => static::normalizePath($request->path()),
            'status'      => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'user_id'     => $user?->id,
            'company_id'  => $user?->company_id ?? null,
        ]);

        return $response;
    }

    /**
     * Substitui segmentos de rota que são IDs por um placeholder genérico,
     * de modo que "/api/conversations/123/messages" e "/api/conversations/456/messages"
     * sejam agrupados como "/api/conversations/{id}/messages" nas métricas.
     *
     * Substitui:
     *  - UUIDs  : 550e8400-e29b-41d4-a716-446655440000  → {id}
     *  - Inteiros: 123                                   → {id}
     */
    private static function normalizePath(string $path): string
    {
        // Remove prefixo 'api/' para log mais limpo
        $path = preg_replace('#^api/#', '/', $path) ?? $path;

        // UUID v4
        $path = preg_replace(
            '#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}#i',
            '/{id}',
            $path
        ) ?? $path;

        // Inteiros puros
        $path = preg_replace('#/\d+#', '/{id}', $path) ?? $path;

        return '/' . ltrim($path, '/');
    }
}

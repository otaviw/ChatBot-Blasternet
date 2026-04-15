<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Handler global de exceções para rotas JSON/API.
 *
 * Responsabilidades:
 *  1. Converter qualquer Throwable em resposta JSON padronizada { message, error, code }
 *  2. Logar apenas o que precisa ser logado — erros 5xx e acessos negados (403)
 *  3. Nunca vazar internals (stack trace, mensagem de DB, etc.) para o cliente
 *
 * Para rotas não-JSON (ex: página SPA), devolve null e deixa o Laravel renderizar
 * normalmente — o SPA recebe o HTML e inicializa a sua própria tela de erro.
 */
class ApiExceptionHandler
{
    public static function configure(Exceptions $exceptions): void
    {
        $exceptions->report(static fn (Throwable $e) => static::report($e));
        $exceptions->render(static fn (Throwable $e, Request $request) => static::render($e, $request));
    }

    // -------------------------------------------------------------------------
    // Report — logging seletivo
    // -------------------------------------------------------------------------

    /**
     * Retorna false para impedir que o handler padrão do Laravel também logue
     * a mesma exceção (evita entradas duplicadas nos canais de log).
     */
    private static function report(Throwable $e): false
    {
        $status = static::statusFor($e);

        if ($status >= 500) {
            // Erros de servidor são inesperados — log completo para investigação
            Log::error('Unhandled exception.', [
                'exception'  => get_class($e),
                'message'    => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'url'        => request()->fullUrl(),
                'method'     => request()->method(),
                'user_id'    => request()->user()?->id,
                'trace'      => static::summarizeTrace($e),
            ]);
        } elseif ($e instanceof AuthorizationException) {
            // 403 pode indicar varredura de endpoints — logar como warning de segurança
            Log::warning('Acesso negado.', [
                'user_id' => request()->user()?->id,
                'url'     => request()->fullUrl(),
                'method'  => request()->method(),
                'ip'      => request()->ip(),
            ]);
        }
        // Erros 4xx restantes (ValidationException, AuthenticationException,
        // ModelNotFoundException, ThrottleRequestsException, etc.) são esperados
        // e não precisam de log — apenas aumentariam o ruído.

        return false;
    }

    // -------------------------------------------------------------------------
    // Render — resposta padronizada
    // -------------------------------------------------------------------------

    private static function render(Throwable $e, Request $request): ?JsonResponse
    {
        // Não intercepta requisições não-JSON (ex: browser carregando a SPA).
        // Retornar null faz o Laravel usar seu renderer padrão para HTML.
        if (! $request->expectsJson()) {
            return null;
        }

        $status = static::statusFor($e);

        $body = [
            'message' => static::messageFor($e, $status),
            'error'   => static::errorCodeFor($e, $status),
            'code'    => $status,
        ];

        // Erros de validação incluem o mapa campo → mensagens para o frontend
        if ($e instanceof ValidationException) {
            $body['errors'] = $e->errors();
        }

        $response = response()->json($body, $status);

        // Preserva headers HTTP semânticos da exceção (ex: Retry-After em 429,
        // Allow em 405) — o cliente pode usá-los para adaptar o comportamento.
        if ($e instanceof HttpException && $e->getHeaders() !== []) {
            foreach ($e->getHeaders() as $name => $value) {
                $response->header($name, $value);
            }
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Helpers — mapeamento de tipo de exceção
    // -------------------------------------------------------------------------

    private static function statusFor(Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException         => 422,
            $e instanceof AuthenticationException     => 401,
            $e instanceof AuthorizationException      => 403,
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException       => 404,
            $e instanceof MethodNotAllowedHttpException => 405,
            $e instanceof ThrottleRequestsException   => 429,
            $e instanceof PostTooLargeException       => 413,
            // HttpException cobre NotFoundHttpException e outros — deve vir depois
            // dos tipos mais específicos para não shadowing-los
            $e instanceof HttpException               => $e->getStatusCode(),
            default                                   => 500,
        };
    }

    private static function errorCodeFor(Throwable $e, int $status): string
    {
        return match (true) {
            $e instanceof ValidationException         => 'validation_error',
            $e instanceof AuthenticationException     => 'unauthenticated',
            $e instanceof AuthorizationException      => 'forbidden',
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException       => 'not_found',
            $e instanceof MethodNotAllowedHttpException => 'method_not_allowed',
            $e instanceof ThrottleRequestsException   => 'too_many_requests',
            $e instanceof PostTooLargeException       => 'payload_too_large',
            $status >= 500                            => 'server_error',
            default                                   => 'http_error',
        };
    }

    private static function messageFor(Throwable $e, int $status): string
    {
        // Nunca expõe internals para erros de servidor — mensagem do PHP pode
        // conter nomes de tabelas, colunas, queries, paths do sistema, etc.
        if ($status >= 500) {
            return 'Erro interno do servidor. Tente novamente em instantes.';
        }

        return match (true) {
            $e instanceof ValidationException         => 'Os dados enviados são inválidos.',
            $e instanceof AuthenticationException     => 'Não autenticado. Faça login para continuar.',
            $e instanceof AuthorizationException      => 'Sem permissão para realizar esta ação.',
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException       => 'Recurso não encontrado.',
            $e instanceof MethodNotAllowedHttpException => 'Método HTTP não permitido.',
            $e instanceof ThrottleRequestsException   => 'Muitas requisições. Tente novamente em instantes.',
            $e instanceof PostTooLargeException       => 'O payload da requisição é muito grande.',
            // Para HttpException genérica, a mensagem pode ser do nosso próprio código
            // (ex: abort(403, 'Empresa inativa')) — é seguro expô-la
            $e instanceof HttpException               => (string) ($e->getMessage() ?: 'Erro HTTP.'),
            default                                   => 'Erro desconhecido.',
        };
    }

    /**
     * Resumo do stack trace — apenas os primeiros N frames, sem argumentos,
     * e com paths relativos à raiz do projeto para não expor o filesystem.
     *
     * @return array<int, array{file: string|null, line: int|null, call: string}>
     */
    private static function summarizeTrace(Throwable $e): array
    {
        return collect($e->getTrace())
            ->take(10)
            ->map(static fn (array $frame): array => [
                'file' => isset($frame['file'])
                    ? str_replace(base_path() . DIRECTORY_SEPARATOR, '', $frame['file'])
                    : null,
                'line' => $frame['line'] ?? null,
                'call' => ($frame['class'] ?? '')
                    . ($frame['type'] ?? '')
                    . ($frame['function'] ?? '')
                    . '()',
            ])
            ->all();
    }
}

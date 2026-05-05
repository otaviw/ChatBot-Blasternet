<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
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

class ApiExceptionHandler
{
    public static function configure(Exceptions $exceptions): void
    {
        $exceptions->report(static fn (Throwable $e) => static::report($e));
        $exceptions->render(static fn (Throwable $e, Request $request) => static::render($e, $request));
    }

    private static function report(Throwable $e): false
    {
        $status = static::statusFor($e);

        if ($status >= 500) {
            Log::error('Unhandled exception.', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'user_id' => request()->user()?->id,
                'trace' => static::summarizeTrace($e),
            ]);
        } elseif ($e instanceof AuthorizationException) {
            Log::warning('Acesso negado.', [
                'user_id' => request()->user()?->id,
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'ip' => request()->ip(),
            ]);
        }

        return false;
    }

    private static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if ($e instanceof HttpResponseException) {
            $response = $e->getResponse();

            return $response instanceof JsonResponse ? $response : response()->json([
                'message' => 'Muitas requisicoes. Tente novamente em instantes.',
                'code' => 'TOO_MANY_REQUESTS',
                'error' => [
                    'code' => 'TOO_MANY_REQUESTS',
                    'message' => 'Muitas requisicoes. Tente novamente em instantes.',
                    'details' => null,
                ],
            ], $response->getStatusCode(), $response->headers->all());
        }

        if (! $request->expectsJson()) {
            return null;
        }

        $status = static::statusFor($e);
        $details = $e instanceof ValidationException ? $e->errors() : null;

        $code = static::errorCodeFor($e, $status);
        $message = static::messageFor($e, $status);

        $body = [
            'message' => $message,
            'code' => $code,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ];

        if ($details !== null) {
            $body['errors'] = $details;
        }

        $response = response()->json($body, $status);

        if ($e instanceof HttpException && $e->getHeaders() !== []) {
            foreach ($e->getHeaders() as $name => $value) {
                $response->header($name, $value);
            }
        }

        return $response;
    }

    private static function statusFor(Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException => 422,
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => 403,
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => 404,
            $e instanceof MethodNotAllowedHttpException => 405,
            $e instanceof ThrottleRequestsException => 429,
            $e instanceof PostTooLargeException => 413,
            $e instanceof HttpResponseException => $e->getResponse()->getStatusCode(),
            $e instanceof HttpException => $e->getStatusCode(),
            default => 500,
        };
    }

    private static function errorCodeFor(Throwable $e, int $status): string
    {
        return match (true) {
            $e instanceof ValidationException => 'VALIDATION_ERROR',
            $e instanceof AuthenticationException => 'UNAUTHENTICATED',
            $e instanceof AuthorizationException => 'FORBIDDEN',
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => 'NOT_FOUND',
            $e instanceof MethodNotAllowedHttpException => 'METHOD_NOT_ALLOWED',
            $e instanceof ThrottleRequestsException,
            $e instanceof HttpResponseException => 'TOO_MANY_REQUESTS',
            $e instanceof PostTooLargeException => 'PAYLOAD_TOO_LARGE',
            $status >= 500 => 'SERVER_ERROR',
            default => 'HTTP_ERROR',
        };
    }

    private static function messageFor(Throwable $e, int $status): string
    {
        if ($status >= 500) {
            return 'Erro interno do servidor.';
        }

        return match (true) {
            $e instanceof ValidationException => 'Os dados enviados sao invalidos.',
            $e instanceof AuthenticationException => 'Nao autenticado. Faca login para continuar.',
            $e instanceof AuthorizationException => 'Sem permissao para realizar esta acao.',
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => 'Recurso nao encontrado.',
            $e instanceof MethodNotAllowedHttpException => 'Metodo HTTP nao permitido.',
            $e instanceof ThrottleRequestsException,
            $e instanceof HttpResponseException => 'Muitas requisicoes. Tente novamente em instantes.',
            $e instanceof PostTooLargeException => 'O payload da requisicao e muito grande.',
            $e instanceof HttpException => (string) ($e->getMessage() ?: 'Erro HTTP.'),
            default => 'Erro desconhecido.',
        };
    }

    /**
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

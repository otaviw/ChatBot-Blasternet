<?php

declare(strict_types=1);


namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestTrackingMiddleware
{
    public const HEADER_NAME = 'X-Request-ID';

    public const ATTRIBUTE_KEY = 'request_id';

    private const MAX_REQUEST_ID_LENGTH = 128;

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $request->attributes->set(self::ATTRIBUTE_KEY, $requestId);
        Log::withContext([
            'request_id' => $requestId,
        ]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER_NAME, $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $incoming = trim((string) $request->headers->get(self::HEADER_NAME, ''));
        if ($this->isValidRequestId($incoming)) {
            return $incoming;
        }

        return (string) Str::uuid();
    }

    private function isValidRequestId(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (mb_strlen($value) > self::MAX_REQUEST_ID_LENGTH) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9._:-]+$/', $value);
    }
}

<?php

declare(strict_types=1);


namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        return $next($request);
    }
}

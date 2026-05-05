<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'authenticated' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        if (! $user->hasPermission($permission)) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Permissao insuficiente para esta acao.',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}

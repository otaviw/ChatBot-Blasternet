<?php

declare(strict_types=1);


namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * Resposta de erro padronizada: {message, error, code}.
     */
    protected function errorResponse(string $message, string $error, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error'   => $error,
            'code'    => $status,
        ], $status);
    }

    /**
     * Resposta padrão para requisições não autenticadas.
     */
    protected function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'authenticated' => false,
            'redirect'      => '/entrar',
        ], 403);
    }

    /**
     * Retorna a resposta de não autenticado se não houver usuário, ou null para continuar.
     */
    protected function guardUnauthenticated(Request $request): ?JsonResponse
    {
        return $request->user() ? null : $this->unauthenticatedResponse();
    }

    /**
     * Resolve o company_id do request respeitando papel do usuário.
     * Admin de sistema usa o company_id do query param; demais usam o da conta.
     */
    protected function resolveCompanyId(Request $request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $companyId = $user->isSystemAdmin()
            ? (int) $request->integer('company_id', 0)
            : (int) $user->company_id;

        return $companyId > 0 ? $companyId : null;
    }
}

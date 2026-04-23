<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * Resposta de erro padronizada: {message, error, code}.
     *
     * @param  string  $message  Mensagem legível (exibível ao usuário)
     * @param  string  $error    Código de erro em snake_case (para o cliente tratar programaticamente)
     * @param  int     $status   HTTP status code
     */
    protected function errorResponse(string $message, string $error, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error'   => $error,
            'code'    => $status,
        ], $status);
    }
}

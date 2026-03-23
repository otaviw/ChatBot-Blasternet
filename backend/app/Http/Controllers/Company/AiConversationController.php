<?php

namespace App\Http\Controllers\Company;

use App\Actions\Company\Ai\CreateCompanyAiConversationAction;
use App\Actions\Company\Ai\ListCompanyAiConversationsAction;
use App\Actions\Company\Ai\SendCompanyAiConversationMessageAction;
use App\Actions\Company\Ai\ShowCompanyAiConversationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendInternalAiConversationMessageRequest;
use App\Http\Requests\StoreInternalAiConversationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AiConversationController extends Controller
{
    public function index(Request $request, ListCompanyAiConversationsAction $action): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        if (! $user) {
            return $this->unauthenticatedResponse();
        }

        return response()->json($action->handle($user, $request));
    }

    public function store(
        StoreInternalAiConversationRequest $request,
        CreateCompanyAiConversationAction $action
    ): JsonResponse {
        $user = $this->resolveAuthenticatedUser($request);
        if (! $user) {
            return $this->unauthenticatedResponse();
        }

        try {
            $payload = $action->handle($user, $request->validated());
        } catch (ValidationException $exception) {
            return $this->validationErrorResponse($exception, 'Nao foi possivel criar a conversa interna de IA.');
        }

        return response()->json($payload, 201);
    }

    public function show(Request $request, int $conversationId, ShowCompanyAiConversationAction $action): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        if (! $user) {
            return $this->unauthenticatedResponse();
        }

        $payload = $action->handle($user, $conversationId, $request);
        if (! $payload) {
            return response()->json([
                'message' => 'Conversa interna de IA nao encontrada para este usuario.',
            ], 404);
        }

        return response()->json($payload);
    }

    public function sendMessage(
        SendInternalAiConversationMessageRequest $request,
        int $conversationId,
        SendCompanyAiConversationMessageAction $action
    ): JsonResponse {
        $user = $this->resolveAuthenticatedUser($request);
        if (! $user) {
            return $this->unauthenticatedResponse();
        }

        try {
            $payload = $action->handle($user, $conversationId, $request->validated());
        } catch (ValidationException $exception) {
            return $this->validationErrorResponse($exception, 'Nao foi possivel enviar mensagem para a IA.');
        }

        if (! $payload) {
            return response()->json([
                'message' => 'Conversa interna de IA nao encontrada para este usuario.',
            ], 404);
        }

        return response()->json($payload);
    }

    private function validationErrorResponse(ValidationException $exception, string $fallback): JsonResponse
    {
        $errors = $exception->errors();
        $message = collect($errors)->flatten()->first();

        return response()->json([
            'message' => $message ?: $fallback,
            'errors' => $errors,
        ], 422);
    }

    private function resolveAuthenticatedUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User && (bool) $user->is_active ? $user : null;
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'authenticated' => false,
            'redirect' => '/entrar',
        ], 403);
    }
}

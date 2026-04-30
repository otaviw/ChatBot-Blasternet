<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Actions\Company\Ai\CreateCompanyAiConversationAction;
use App\Actions\Company\Ai\ListCompanyAiConversationsAction;
use App\Actions\Company\Ai\SendCompanyAiConversationMessageAction;
use App\Actions\Company\Ai\ShowCompanyAiConversationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendInternalAiConversationMessageRequest;
use App\Http\Requests\StoreInternalAiConversationRequest;
use App\Models\User;
use App\Services\Ai\InternalAiChatStreamService;
use App\Services\Ai\InternalAiConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiConversationController extends Controller
{
    public function index(Request $request, ListCompanyAiConversationsAction $action): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        if (! $user) {
            return $this->unauthenticatedResponse();
        }

        try {
            $payload = $action->handle($user, $request);
        } catch (ValidationException $exception) {
            return $this->validationErrorResponse($exception, 'Não foi possível listar conversas internas de IA.');
        }

        return response()->json($payload);
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
            $payload = $action->handle($user, $request);
        } catch (ValidationException $exception) {
            return $this->validationErrorResponse($exception, 'Não foi possível criar a conversa interna de IA.');
        }

        return response()->json($payload, 201);
    }

    public function show(Request $request, int $conversationId, ShowCompanyAiConversationAction $action): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        if (! $user) {
            return $this->unauthenticatedResponse();
        }

        try {
            $payload = $action->handle($user, $conversationId, $request);
        } catch (ValidationException $exception) {
            return $this->validationErrorResponse($exception, 'Não foi possível carregar a conversa interna de IA.');
        }

        if (! $payload) {
            return response()->json([
                'message' => 'Conversa interna de IA não encontrada para este usuário.',
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
            $payload = $action->handle($user, $conversationId, $request);
        } catch (ValidationException $exception) {
            return $this->validationErrorResponse($exception, 'Não foi possível enviar mensagem para a IA.');
        }

        if (! $payload) {
            return response()->json([
                'message' => 'Conversa interna de IA não encontrada para este usuário.',
            ], 404);
        }

        return response()->json($payload);
    }

    /**
     * SSE streaming endpoint for internal AI chat.
     * Returns a text/event-stream response with delta / done / error events.
     */
    public function streamMessage(
        SendInternalAiConversationMessageRequest $request,
        int $conversationId,
        InternalAiChatStreamService $streamService,
        InternalAiConversationService $conversationService
    ): JsonResponse|Response {
        $user = $this->resolveAuthenticatedUser($request);
        if (! $user) {
            return $this->unauthenticatedResponse();
        }

        $companyId = $user->isSystemAdmin()
            ? ((int) $request->input('company_id', 0) ?: null)
            : null;

        $conversation = $conversationService->findForUser($user, $conversationId, $companyId);
        if (! $conversation) {
            return response()->json(['message' => 'Conversa interna de IA não encontrada.'], 404);
        }

        $content = (string) ($request->input('content') ?? $request->input('text') ?? '');

        $controller = $this;

        return response()->stream(
            function () use ($user, $conversation, $content, $companyId, $streamService, $conversationService, $controller): void {
                try {
                    $result = $streamService->streamMessage(
                        user: $user,
                        content: $content,
                        conversation: $conversation,
                        onChunk: static function (string $chunk) use ($controller): void {
                            echo 'data: '.json_encode(
                                ['type' => 'delta', 'content' => $chunk],
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            )."\n\n";
                            $controller->flushSseOutput();
                        },
                        companyId: $companyId
                    );

                    echo 'data: '.json_encode([
                        'type' => 'done',
                        'user_message' => $conversationService->serializeMessage($result['user_message']),
                        'assistant_message' => $conversationService->serializeMessage($result['assistant_message']),
                        'conversation' => $conversationService->serializeConversationSummary($result['conversation']),
                        'provider' => $result['provider'],
                        'model' => $result['model'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                    $controller->flushSseOutput();

                } catch (ValidationException $exception) {
                    $errors = $exception->errors();
                    $message = collect($errors)->flatten()->first()
                        ?? 'Não foi possível processar a mensagem.';

                    echo 'data: '.json_encode(
                        ['type' => 'error', 'message' => $message],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )."\n\n";
                    $controller->flushSseOutput();

                } catch (Throwable) {
                    echo 'data: '.json_encode(
                        ['type' => 'error', 'message' => 'Erro inesperado ao processar a mensagem.'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )."\n\n";
                    $controller->flushSseOutput();
                }
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ]
        );
    }

    public function flushSseOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
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

}

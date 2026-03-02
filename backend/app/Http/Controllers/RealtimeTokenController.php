<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RealtimeTokenController extends Controller
{
    public function __construct(
        private JwtTokenService $jwt
    ) {}

    public function issueSocketToken(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        try {
            $ttl = max(5, (int) config('realtime.jwt.token_ttl_seconds', 120));
            $role = User::normalizeRole($user->role);
            $companyId = (int) ($user->company_id ?? 0);

            $result = $this->jwt->createToken([
                'sub' => (string) $user->id,
                'companyId' => $companyId,
                'roles' => [$role],
                'type' => 'socket',
            ], $ttl);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'token' => $result['token'],
            'ttl_seconds' => $ttl,
            'expires_at' => now()->setTimestamp($result['expires_at'])->toISOString(),
            'socket_url' => (string) config('realtime.client.url', 'http://localhost:8081'),
            'transports' => ['websocket'],
        ]);
    }

    public function issueConversationJoinToken(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $this->canJoinConversation($user, $conversation)) {
            return response()->json([
                'message' => 'Sem permissao para entrar na room desta conversa.',
            ], 403);
        }

        try {
            $ttl = max(5, (int) config('realtime.jwt.join_token_ttl_seconds', 45));
            $role = User::normalizeRole($user->role);
            $companyId = (int) ($user->company_id ?? 0);

            $result = $this->jwt->createToken([
                'sub' => (string) $user->id,
                'companyId' => $companyId,
                'roles' => [$role],
                'type' => 'conversation_join',
                'conversationId' => (int) $conversation->id,
            ], $ttl);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'conversation_id' => (int) $conversation->id,
            'token' => $result['token'],
            'ttl_seconds' => $ttl,
            'expires_at' => now()->setTimestamp($result['expires_at'])->toISOString(),
        ]);
    }

    private function canJoinConversation(User $user, Conversation $conversation): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isCompanyUser()
            && (int) $user->company_id === (int) $conversation->company_id;
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\AuditLogService;
use App\Support\AdminPrivacySanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private AuditLogService $auditLog
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $query = Conversation::query()
            ->with(['company:id,name'])
            ->withCount('messages')
            ->latest();
        $companyId = $request->query('company_id');
        if ($companyId) {
            $query->where('company_id', (int) $companyId);
        }

        $conversations = $query->limit(150)->get();
        $sanitized = AdminPrivacySanitizer::conversationSummaryCollection($conversations);
        $byStatus = $conversations
            ->groupBy(fn(Conversation $conversation) => (string) $conversation->status)
            ->map(fn($items) => count($items))
            ->all();

        return response()->json([
            'authenticated' => true,
            'role' => 'admin',
            'privacy_mode' => 'blind_default',
            'conversations' => $sanitized,
            'metrics' => [
                'total' => count($sanitized),
                'by_status' => $byStatus,
            ],
        ]);
    }

    public function show(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $conversation = Conversation::query()
            ->with(['company:id,name'])
            ->withCount('messages')
            ->find($conversationId);

        if (! $conversation) {
            return response()->json([
                'message' => 'Conversa nao encontrada.',
            ], 404);
        }

        return response()->json([
            'authenticated' => true,
            'role' => 'admin',
            'privacy_mode' => 'blind_default',
            'conversation' => AdminPrivacySanitizer::conversationSummary($conversation),
        ]);
    }

    public function assume(Request $request, int $conversationId): JsonResponse
    {
        return $this->blockedByPrivacyMode($request, 'admin.conversation.assume_blocked', $conversationId);
    }

    public function release(Request $request, int $conversationId): JsonResponse
    {
        return $this->blockedByPrivacyMode($request, 'admin.conversation.release_blocked', $conversationId);
    }

    public function manualReply(Request $request, int $conversationId): JsonResponse
    {
        return $this->blockedByPrivacyMode($request, 'admin.conversation.manual_reply_blocked', $conversationId);
    }

    public function close(Request $request, int $conversationId): JsonResponse
    {
        return $this->blockedByPrivacyMode($request, 'admin.conversation.close_blocked', $conversationId);
    }

    public function updateTags(Request $request, int $conversationId): JsonResponse
    {
        return $this->blockedByPrivacyMode($request, 'admin.conversation.tags_update_blocked', $conversationId);
    }

    public function updateContact(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:160'],
        ]);

        $conversation = Conversation::query()
            ->with(['company:id,name'])
            ->withCount('messages')
            ->find($conversationId);

        if (! $conversation) {
            return response()->json([
                'message' => 'Conversa nao encontrada.',
            ], 404);
        }

        $customerName = trim((string) ($validated['customer_name'] ?? ''));
        $customerName = $customerName !== '' ? $customerName : null;
        $before = $conversation->customer_name;
        $conversation->customer_name = $customerName;
        $conversation->save();

        $this->auditLog->record($request, 'admin.conversation.contact_updated', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'before_customer_name' => $before,
            'after_customer_name' => $conversation->customer_name,
        ]);

        return response()->json([
            'ok' => true,
            'privacy_mode' => 'blind_default',
            'conversation' => AdminPrivacySanitizer::conversationSummary($conversation),
        ]);
    }

    private function blockedByPrivacyMode(Request $request, string $action, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $conversation = Conversation::query()
            ->select(['id', 'company_id'])
            ->find($conversationId);

        $this->auditLog->record(
            $request,
            $action,
            $conversation?->company_id,
            [
                'conversation_id' => $conversationId,
            ]
        );

        return response()->json([
            'message' => 'Operacao bloqueada para superadmin no modo privacidade.',
            'privacy_mode' => 'blind_default',
        ], 403);
    }
}

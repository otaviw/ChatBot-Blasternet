<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Conversation\SearchConversationsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SearchConversationsRequest;
use App\Http\Requests\Admin\UpdateConversationContactRequest;
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
        $query = Conversation::query()
            ->with(['company:id,name'])
            ->withCount('messages')
            ->withCount('tags')
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
        $conversation = Conversation::query()
            ->with(['company:id,name'])
            ->withCount('messages')
            ->withCount('tags')
            ->find($conversationId);

        if (! $conversation) {
            return response()->json([
                'message' => 'Conversa não encontrada.',
            ], 404);
        }

        return response()->json([
            'authenticated' => true,
            'role' => 'admin',
            'privacy_mode' => 'blind_default',
            'conversation' => AdminPrivacySanitizer::conversationSummary($conversation),
        ]);
    }

    public function search(SearchConversationsRequest $request, SearchConversationsAction $action): JsonResponse
    {
        $validated = $request->validated();

        return response()->json($action->handleForAdmin((int) $validated['empresa_id'], $validated));
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

    public function updateContact(UpdateConversationContactRequest $request, int $conversationId): JsonResponse
    {
        $validated = $request->validated();

        $conversation = Conversation::query()
            ->with(['company:id,name'])
            ->withCount('messages')
            ->withCount('tags')
            ->find($conversationId);

        if (! $conversation) {
            return response()->json([
                'message' => 'Conversa não encontrada.',
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
            'message' => 'Operação bloqueada para superadmin no modo privacidade.',
            'privacy_mode' => 'blind_default',
        ], 403);
    }
}

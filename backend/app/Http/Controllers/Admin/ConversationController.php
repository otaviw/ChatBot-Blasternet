<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AuditLogService;
use App\Services\WhatsAppSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private WhatsAppSendService $whatsAppSend,
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
            ->with(['company', 'assignedUser:id,name,email'])
            ->withCount('messages')
            ->latest();
        $companyId = $request->query('company_id');
        if ($companyId) {
            $query->where('company_id', (int) $companyId);
        }

        $conversations = $query->limit(150)->get();

        return response()->json([
            'authenticated' => true,
            'role' => 'admin',
            'conversations' => $conversations,
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

        $conversation = Conversation::with([
            'company',
            'messages' => fn($q) => $q->oldest(),
            'assignedUser:id,name,email',
        ])->find($conversationId);

        if (! $conversation) {
            return response()->json([
                'message' => 'Conversa nao encontrada.',
            ], 404);
        }

        return response()->json([
            'authenticated' => true,
            'role' => 'admin',
            'conversation' => $conversation,
        ]);
    }

    public function assume(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $conversation = Conversation::find($conversationId);
        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada.'], 404);
        }

        $conversation->handling_mode = 'human';
        $conversation->assigned_type = 'user';
        $conversation->assigned_id = $user->id;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = $user->id;
        $conversation->assigned_area = null;
        $conversation->assumed_at = now();
        $conversation->status = 'in_progress';
        $conversation->save();

        $this->auditLog->record($request, 'admin.conversation.assumed', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'assigned_type' => 'user',
            'assigned_id' => $user->id,
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $conversation->load('assignedUser:id,name,email'),
        ]);
    }

    public function release(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $conversation = Conversation::find($conversationId);
        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada.'], 404);
        }

        $conversation->handling_mode = 'bot';
        $conversation->assigned_type = 'bot';
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $conversation->status = 'open';
        $conversation->save();

        $this->auditLog->record($request, 'admin.conversation.released', $conversation->company_id, [
            'conversation_id' => $conversation->id,
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function manualReply(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'send_outbound' => ['sometimes', 'boolean'],
        ]);

        $conversation = Conversation::with('company')->find($conversationId);
        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada.'], 404);
        }

        if (! $conversation->isManualMode()) {
            $conversation->handling_mode = 'human';
            $conversation->assigned_type = 'user';
            $conversation->assigned_id = $user->id;
            $conversation->current_area_id = null;
            $conversation->assigned_user_id = $user->id;
            $conversation->assigned_area = null;
            $conversation->assumed_at = now();
        } elseif ($conversation->assigned_type === 'user' && (int) $conversation->assigned_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Conversa assumida por outro operador.',
            ], 409);
        } elseif (in_array($conversation->assigned_type, ['area', 'bot', 'unassigned'], true)) {
            $conversation->assigned_type = 'user';
            $conversation->assigned_id = $user->id;
            $conversation->current_area_id = null;
            $conversation->assigned_user_id = $user->id;
            $conversation->assigned_area = null;
            $conversation->assumed_at = now();
        }

        $conversation->status = 'in_progress';
        $conversation->save();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'type' => 'human',
            'text' => trim((string) $validated['text']),
            'meta' => [
                'source' => 'manual',
                'actor_user_id' => $user->id,
            ],
        ]);

        $sendOutbound = (bool) ($validated['send_outbound'] ?? true);
        $wasSent = $sendOutbound
            ? $this->whatsAppSend->sendText($conversation->company, $conversation->customer_phone, $message->text)
            : false;

        $this->auditLog->record($request, 'admin.conversation.manual_reply', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'sent' => $wasSent,
        ]);

        return response()->json([
            'ok' => true,
            'message' => $message,
            'was_sent' => $wasSent,
            'conversation' => $conversation->load('assignedUser:id,name,email'),
        ]);
    }

    public function close(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $conversation = Conversation::find($conversationId);
        if (!$conversation) {
            return response()->json(['message' => 'Conversa nao encontrada.'], 404);
        }

        $conversation->status = 'closed';
        $conversation->handling_mode = 'bot';
        $conversation->assigned_type = 'unassigned';
        $conversation->assigned_id = null;
        $conversation->current_area_id = null;
        $conversation->assigned_user_id = null;
        $conversation->assigned_area = null;
        $conversation->assumed_at = null;
        $conversation->closed_at = now();
        $conversation->save();

        $this->auditLog->record($request, 'admin.conversation.closed', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'closed_by' => $user->id,
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $conversation,
        ]);
    }

    public function updateTags(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['authenticated' => false, 'redirect' => '/entrar'], 403);
        }

        $validated = $request->validate([
            'tags'   => ['present', 'array'],
            'tags.*' => ['string', 'max:50'],
        ]);

        $conversation = Conversation::find($conversationId);
        if (!$conversation) {
            return response()->json(['message' => 'Conversa nao encontrada.'], 404);
        }

        // Normaliza: lowercase, sem duplicatas, sem vazios
        $tags = collect($validated['tags'])
            ->map(fn($t) => strtolower(trim($t)))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $conversation->tags = $tags;
        $conversation->save();

        $this->auditLog->record($request, 'admin.conversation.tags_updated', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'tags' => $tags,
        ]);

        return response()->json([
            'ok' => true,
            'tags' => $tags,
        ]);
    }
}

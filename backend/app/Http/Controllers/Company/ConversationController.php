<?php

namespace App\Http\Controllers\Company;

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
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }
        $companyId = (int) $user->company_id;

        $conversations = Conversation::where('company_id', $companyId)
            ->latest()
            ->with('assignedUser:id,name,email')
            ->withCount('messages')
            ->limit(100)
            ->get();

        return response()->json([
            'authenticated' => true,
            'role' => 'company',
            'conversations' => $conversations,
        ]);
    }

    public function show(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }
        $companyId = (int) $user->company_id;

        $conversation = Conversation::where('company_id', $companyId)
            ->whereKey($conversationId)
            ->with([
                'messages' => fn ($q) => $q->oldest(),
                'assignedUser:id,name,email',
            ])
            ->first();

        if (! $conversation) {
            return response()->json([
                'message' => 'Conversa nao encontrada para esta empresa.',
            ], 404);
        }

        return response()->json([
            'authenticated' => true,
            'role' => 'company',
            'conversation' => $conversation,
        ]);
    }

    public function assume(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $conversation = Conversation::where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        $conversation->handling_mode = 'manual';
        $conversation->assigned_user_id = $user->id;
        $conversation->assumed_at = now();
        $conversation->status = 'in_progress';
        $conversation->save();

        $this->auditLog->record($request, 'company.conversation.assumed', $conversation->company_id, [
            'conversation_id' => $conversation->id,
            'assigned_user_id' => $user->id,
        ]);

        return response()->json([
            'ok' => true,
            'conversation' => $conversation->load('assignedUser:id,name,email'),
        ]);
    }

    public function release(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $conversation = Conversation::where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        $conversation->handling_mode = 'bot';
        $conversation->assigned_user_id = null;
        $conversation->assumed_at = null;
        $conversation->status = 'open';
        $conversation->save();

        $this->auditLog->record($request, 'company.conversation.released', $conversation->company_id, [
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
        if (! $user || ! $user->isCompanyUser()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'send_outbound' => ['sometimes', 'boolean'],
        ]);

        $conversation = Conversation::where('company_id', (int) $user->company_id)
            ->whereKey($conversationId)
            ->with('company')
            ->first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversa nao encontrada para esta empresa.'], 404);
        }

        if (! $conversation->isManualMode()) {
            $conversation->handling_mode = 'manual';
            $conversation->assigned_user_id = $user->id;
            $conversation->assumed_at = now();
        } elseif ((int) $conversation->assigned_user_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Conversa assumida por outro operador.',
            ], 409);
        }

        $conversation->status = 'in_progress';
        $conversation->save();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
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

        $this->auditLog->record($request, 'company.conversation.manual_reply', $conversation->company_id, [
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
}

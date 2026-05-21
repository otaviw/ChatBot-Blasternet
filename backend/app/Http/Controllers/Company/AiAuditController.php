<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AiAuditLog;
use App\Models\AiMessage;
use App\Models\Company;
use App\Models\Message as InboxMessage;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAuditController extends Controller
{
    public function __construct(
        private readonly AiAccessService $aiAccess
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! (bool) $user->is_active) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $this->aiAccess->canManageAi($user)) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode acessar a auditoria da IA.',
            ], 403);
        }

        $companies = $user->isSystemAdmin()
            ? Company::orderBy('name')->get(['id', 'name'])
            : null;

        $companyId = $user->isSystemAdmin()
            ? (int) $request->integer('company_id', 0)
            : (int) $user->company_id;
        $userId = (int) $request->integer('user_id', 0);
        $type = mb_strtolower(trim((string) $request->query('type', 'all')));
        $source = mb_strtolower(trim((string) $request->query('source', 'all')));
        $contact = trim((string) $request->query('contact', ''));

        $start = $this->parseDateOrNull((string) $request->query('start_date', ''), true);
        $end = $this->parseDateOrNull((string) $request->query('end_date', ''), false);

        $query = AiAuditLog::query()->with(['user:id,name']);

        if ($companyId > 0) {
            $query->where('company_id', $companyId);
        }

        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        if ($start !== null) {
            $query->where('created_at', '>=', $start);
        }

        if ($end !== null) {
            $query->where('created_at', '<=', $end);
        }

        if ($type === 'message') {
            $query->where('action', AiAuditLog::ACTION_MESSAGE_SENT);
        } elseif ($type === 'tool') {
            $query->whereIn('action', [AiAuditLog::ACTION_TOOL_EXECUTED, AiAuditLog::ACTION_TOOL_FAILED]);
        } elseif ($type === 'safety') {
            $query->where('action', AiAuditLog::ACTION_SAFETY_BLOCKED);
        }

        if ($source !== '' && $source !== 'all') {
            $query->where(function ($sourceQuery) use ($source): void {
                $sourceQuery->where('source', $source)
                    ->orWhere('metadata->source', $source);
            });
        }

        if ($contact !== '') {
            $contactLike = '%'.$contact.'%';
            $query->where(function ($contactQuery) use ($contactLike): void {
                $contactQuery
                    ->where('contact_name', 'like', $contactLike)
                    ->orWhere('metadata->contact_name', 'like', $contactLike)
                    ->orWhere('metadata->customer_name', 'like', $contactLike);
            });
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $page = max(1, (int) $request->integer('page', 1));

        $logs = $query
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = $logs->getCollection()->map(function (AiAuditLog $log): array {
            $meta = is_array($log->metadata) ? $log->metadata : [];
            $status = $this->resolveStatus($log, $meta);
            $action = (string) $log->action;

            return [
                'id' => (int) $log->id,
                'type' => $this->resolveType($action),
                'action' => $action,
                'action_label' => $this->humanizeAction($action, $meta),
                'source' => (string) ($log->source ?? $meta['source'] ?? ''),
                'source_label' => $this->humanizeSource((string) ($log->source ?? $meta['source'] ?? '')),
                'user_id' => $log->user_id ? (int) $log->user_id : null,
                'user_name' => (string) ($log->user?->name ?? 'Sistema'),
                'conversation_id' => $log->conversation_id ? (int) $log->conversation_id : null,
                'inbox_conversation_id' => $log->inbox_conversation_id ? (int) $log->inbox_conversation_id : ($this->metaInt($meta, 'inbox_conversation_id')),
                'message_id' => $log->message_id ? (int) $log->message_id : ($this->metaInt($meta, 'message_id')),
                'decision_log_id' => $log->decision_log_id ? (int) $log->decision_log_id : ($this->metaInt($meta, 'decision_log_id')),
                'contact_name' => (string) ($log->contact_name ?? $meta['contact_name'] ?? $meta['customer_name'] ?? ''),
                'contact_phone_hash' => (string) ($log->contact_phone_hash ?? $meta['contact_phone_hash'] ?? $meta['customer_phone_hash'] ?? ''),
                'message' => (string) ($meta['user_message'] ?? ''),
                'assistant_response' => (string) ($meta['assistant_response'] ?? ''),
                'tool_used' => (string) ($meta['tool_used'] ?? $meta['tool'] ?? ''),
                'status' => $status,
                'created_at' => $log->created_at?->toISOString(),
            ];
        })->values();

        $usersQuery = User::query()
            ->whereIn('role', User::companyRoleValues())
            ->orderBy('name');

        if ($companyId > 0) {
            $usersQuery->where('company_id', $companyId);
        }

        $users = $usersQuery->get(['id', 'name'])
            ->map(fn (User $companyUser) => [
                'id' => (int) $companyUser->id,
                'name' => (string) $companyUser->name,
            ])
            ->values();

        return response()->json([
            'authenticated' => true,
            'is_admin' => $user->isSystemAdmin(),
            'companies' => $companies,
            'selected_company_id' => $companyId > 0 ? $companyId : null,
            'filters' => [
                'user_id' => $userId > 0 ? $userId : null,
                'type' => in_array($type, ['all', 'message', 'tool', 'safety'], true) ? $type : 'all',
                'source' => in_array($source, ['all', 'chatbot_whatsapp', 'internal_chat', 'conversation_suggestion'], true) ? $source : 'all',
                'contact' => $contact,
                'start_date' => $start?->toDateString(),
                'end_date' => $end?->toDateString(),
            ],
            'users' => $users,
            'items' => $items,
            'pagination' => [
                'current_page' => (int) $logs->currentPage(),
                'last_page' => (int) $logs->lastPage(),
                'per_page' => (int) $logs->perPage(),
                'total' => (int) $logs->total(),
            ],
        ]);
    }

    public function show(Request $request, int $logId): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! (bool) $user->is_active) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if (! $this->aiAccess->canManageAi($user)) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode acessar a auditoria da IA.',
            ], 403);
        }

        $logQuery = AiAuditLog::query()->whereKey($logId);
        if (! $user->isSystemAdmin()) {
            $logQuery->where('company_id', (int) $user->company_id);
        }

        $log = $logQuery
            ->with(['user:id,name'])
            ->first();

        if (! $log) {
            return response()->json([
                'message' => 'Log de auditoria não encontrado.',
            ], 404);
        }

        $meta = is_array($log->metadata) ? $log->metadata : [];
        $conversationMessages = collect();
        if ($log->conversation_id) {
            $conversationMessages = AiMessage::query()
                ->where('ai_conversation_id', (int) $log->conversation_id)
                ->orderBy('id')
                ->limit(100)
                ->get(['id', 'role', 'content', 'meta', 'created_at']);
        }

        $inboxConversationId = $log->inbox_conversation_id ? (int) $log->inbox_conversation_id : $this->metaInt($meta, 'inbox_conversation_id');
        $inboxMessages = collect();
        if ($inboxConversationId !== null) {
            $inboxMessages = InboxMessage::query()
                ->where('conversation_id', $inboxConversationId)
                ->orderBy('id')
                ->limit(100)
                ->get(['id', 'direction', 'type', 'content_type', 'text', 'meta', 'created_at']);
        }

        $action = (string) $log->action;

        return response()->json([
            'authenticated' => true,
            'item' => [
                'id' => (int) $log->id,
                'type' => $this->resolveType($action),
                'action' => $action,
                'action_label' => $this->humanizeAction($action, $meta),
                'source' => (string) ($log->source ?? $meta['source'] ?? ''),
                'source_label' => $this->humanizeSource((string) ($log->source ?? $meta['source'] ?? '')),
                'user_id' => $log->user_id ? (int) $log->user_id : null,
                'user_name' => (string) ($log->user?->name ?? 'Sistema'),
                'conversation_id' => $log->conversation_id ? (int) $log->conversation_id : null,
                'inbox_conversation_id' => $log->inbox_conversation_id ? (int) $log->inbox_conversation_id : ($this->metaInt($meta, 'inbox_conversation_id')),
                'message_id' => $log->message_id ? (int) $log->message_id : ($this->metaInt($meta, 'message_id')),
                'decision_log_id' => $log->decision_log_id ? (int) $log->decision_log_id : ($this->metaInt($meta, 'decision_log_id')),
                'contact_name' => (string) ($log->contact_name ?? $meta['contact_name'] ?? $meta['customer_name'] ?? ''),
                'contact_phone_hash' => (string) ($log->contact_phone_hash ?? $meta['contact_phone_hash'] ?? $meta['customer_phone_hash'] ?? ''),
                'status' => $this->resolveStatus($log, $meta),
                'metadata' => $meta,
                'created_at' => $log->created_at?->toISOString(),
                'conversation_messages' => $conversationMessages->map(fn (AiMessage $message) => [
                    'id' => (int) $message->id,
                    'role' => (string) $message->role,
                    'content' => (string) ($message->content ?? ''),
                    'meta' => is_array($message->meta) ? $message->meta : [],
                    'created_at' => $message->created_at?->toISOString(),
                ])->values(),
                'inbox_messages' => $inboxMessages->map(fn (InboxMessage $message) => [
                    'id' => (int) $message->id,
                    'role' => (string) ($message->direction === 'in' ? 'cliente' : ($message->type ?: 'bot')),
                    'direction' => (string) $message->direction,
                    'content_type' => (string) $message->content_type,
                    'content' => (string) ($message->text ?? ''),
                    'meta' => is_array($message->meta) ? $message->meta : [],
                    'created_at' => $message->created_at?->toISOString(),
                ])->values(),
            ],
        ]);
    }

    private function resolveStatus(AiAuditLog $log, array $meta): string
    {
        if ($log->action === AiAuditLog::ACTION_TOOL_FAILED) {
            return 'erro';
        }

        $metaStatus = mb_strtolower(trim((string) ($meta['status'] ?? '')));
        if ($metaStatus === 'error' || $metaStatus === 'erro') {
            return 'erro';
        }

        return 'ok';
    }

    private function resolveType(string $action): string
    {
        if ($action === AiAuditLog::ACTION_MESSAGE_SENT) {
            return 'message';
        }

        if ($action === AiAuditLog::ACTION_SAFETY_BLOCKED) {
            return 'safety';
        }

        return 'tool';
    }

    private function humanizeAction(string $action, array $meta = []): string
    {
        $source = trim((string) ($meta['source'] ?? ''));

        return match (trim($action)) {
            AiAuditLog::ACTION_MESSAGE_SENT => $source === 'chatbot_whatsapp'
                ? 'Resposta da IA enviada ao cliente'
                : 'Mensagem enviada para IA',
            AiAuditLog::ACTION_TOOL_EXECUTED => 'Ferramenta executada',
            AiAuditLog::ACTION_TOOL_FAILED => 'Falha ao executar ferramenta',
            AiAuditLog::ACTION_SAFETY_BLOCKED => 'Bloqueada por seguranca',
            default => 'Acao nao informada',
        };
    }

    private function humanizeSource(string $source): string
    {
        return match (trim($source)) {
            'chatbot_whatsapp' => 'Bot WhatsApp',
            'internal_chat' => 'Chat interno',
            'conversation_suggestion' => 'Sugestao de resposta',
            default => $source !== '' ? $source : 'Nao informado',
        };
    }

    private function metaInt(array $meta, string $key): ?int
    {
        if (! is_numeric($meta[$key] ?? null)) {
            return null;
        }

        $value = (int) $meta[$key];

        return $value > 0 ? $value : null;
    }

    private function parseDateOrNull(string $value, bool $start): ?Carbon
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        try {
            $date = Carbon::parse($normalized);
        } catch (\Throwable) {
            return null;
        }

        return $start ? $date->startOfDay() : $date->endOfDay();
    }
}


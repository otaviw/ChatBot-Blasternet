<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AiAuditLog;
use App\Models\AiMessage;
use App\Models\Company;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAuditController extends Controller
{
    public function __construct(
        private AiAccessService $aiAccess
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
        }

        $logs = $query
            ->orderByDesc('id')
            ->paginate(50);

        $items = $logs->getCollection()->map(function (AiAuditLog $log): array {
            $meta = is_array($log->metadata) ? $log->metadata : [];
            $status = $this->resolveStatus($log, $meta);
            $type = $this->resolveType($log->action);

            return [
                'id' => (int) $log->id,
                'type' => $type,
                'action' => (string) $log->action,
                'user_id' => $log->user_id ? (int) $log->user_id : null,
                'user_name' => (string) ($log->user?->name ?? 'Sistema'),
                'conversation_id' => $log->conversation_id ? (int) $log->conversation_id : null,
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
                'type' => in_array($type, ['all', 'message', 'tool'], true) ? $type : 'all',
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

        return response()->json([
            'authenticated' => true,
            'item' => [
                'id' => (int) $log->id,
                'type' => $this->resolveType((string) $log->action),
                'action' => (string) $log->action,
                'user_id' => $log->user_id ? (int) $log->user_id : null,
                'user_name' => (string) ($log->user?->name ?? 'Sistema'),
                'conversation_id' => $log->conversation_id ? (int) $log->conversation_id : null,
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

        return 'tool';
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

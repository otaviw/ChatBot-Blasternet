<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ListAuditLogsRequest;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogController extends Controller
{
    public function index(ListAuditLogsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $validated = $request->validated();

        /** @var User|null $user */
        $user = $request->user();
        if (! $user || ! (bool) $user->is_active) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $query = AuditLog::query()
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select([
                'audit_logs.id',
                'audit_logs.user_id',
                'audit_logs.company_id',
                'audit_logs.reseller_id',
                'audit_logs.action',
                'audit_logs.entity_type',
                'audit_logs.entity_id',
                'audit_logs.created_at',
                'users.name as user_name',
            ])
            ->orderByDesc('audit_logs.id');

        $companyId = (int) ($user->company_id ?? 0);
        $resellerId = $this->resolveResellerId($request, $user);
        if (! $this->applyTenantScope($query, $user, $resellerId, $companyId, (int) ($validated['company_id'] ?? 0))) {
            return response()->json(['message' => 'Sem escopo de empresa/reseller para auditoria.'], 403);
        }

        if (! empty($validated['user_id'])) {
            $query->where('audit_logs.user_id', (int) $validated['user_id']);
        }

        if (! empty($validated['action'])) {
            $query->where('audit_logs.action', trim((string) $validated['action']));
        }

        $start = $this->parseDateOrNull((string) ($validated['start_date'] ?? ''), true);
        if ($start) {
            $query->where('audit_logs.created_at', '>=', $start);
        }

        $end = $this->parseDateOrNull((string) ($validated['end_date'] ?? ''), false);
        if ($end) {
            $query->where('audit_logs.created_at', '<=', $end);
        }

        $logs = $query->paginate(
            perPage: (int) $validated['per_page'],
            columns: ['*'],
            pageName: 'page',
            page: (int) ($validated['page'] ?? 1)
        );

        $logs->setCollection(
            $logs->getCollection()->map(function ($item) {
                $item->user_name = $this->resolveUserName($item->user_name ?? null, $item->user_id ?? null);
                $item->action_label = $this->humanizeAction((string) ($item->action ?? ''));
                return $item;
            })
        );

        return response()->json($logs);
    }

    public function show(Request $request, int $auditLog): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        /** @var User|null $user */
        $user = $request->user();
        if (! $user || ! (bool) $user->is_active) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $query = AuditLog::query()
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select([
                'audit_logs.id',
                'audit_logs.user_id',
                'audit_logs.company_id',
                'audit_logs.reseller_id',
                'audit_logs.action',
                'audit_logs.entity_type',
                'audit_logs.entity_id',
                'audit_logs.old_data',
                'audit_logs.new_data',
                'audit_logs.ip_address',
                'audit_logs.user_agent',
                'audit_logs.created_at',
                'users.name as user_name',
            ])
            ->where('audit_logs.id', $auditLog);

        $companyId = (int) ($user->company_id ?? 0);
        $resellerId = $this->resolveResellerId($request, $user);
        if (! $this->applyTenantScope($query, $user, $resellerId, $companyId, 0)) {
            return response()->json(['message' => 'Sem escopo de empresa/reseller para auditoria.'], 403);
        }

        $item = $query->first();
        if (! $item) {
            return response()->json(['message' => 'Log de auditoria não encontrado.'], 404);
        }

        $item->user_name = $this->resolveUserName($item->user_name ?? null, $item->user_id ?? null);
        $item->action_label = $this->humanizeAction((string) ($item->action ?? ''));
        $item->old_data = $this->decodeJsonField($item->old_data ?? null);
        $item->new_data = $this->decodeJsonField($item->new_data ?? null);

        return response()->json(['item' => $item]);
    }

    private function resolveResellerId(Request $request, User $user): int
    {
        $candidates = [
            $user->reseller_id ?? null,
            $request->attributes->get('reseller_id'),
            session('reseller_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return 0;
    }

    private function applyTenantScope(
        \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query,
        User $user,
        int $resellerId,
        int $companyId,
        int $requestedCompanyId
    ): bool {
        if ($user->isSystemAdmin()) {
            if ($requestedCompanyId > 0) {
                $query->where('audit_logs.company_id', $requestedCompanyId);
            }

            return true;
        }

        if ($resellerId > 0) {
            $query->where('audit_logs.reseller_id', $resellerId);
            if ($requestedCompanyId > 0) {
                $query->where('audit_logs.company_id', $requestedCompanyId);
            }

            return true;
        }

        if ($companyId > 0) {
            $query->where('audit_logs.company_id', $companyId);
            return true;
        }

        return false;
    }

    private function parseDateOrNull(string $value, bool $startOfDay): ?Carbon
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($normalized);
        } catch (\Throwable) {
            return null;
        }

        return $startOfDay ? $parsed->startOfDay() : $parsed->endOfDay();
    }

    private function resolveUserName(mixed $name, mixed $userId): string
    {
        $normalized = trim((string) $name);
        if ($normalized !== '') {
            return $normalized;
        }

        return is_numeric($userId) && (int) $userId > 0 ? 'Usuário removido' : 'Sistema';
    }

    private function humanizeAction(string $action): string
    {
        $known = [
            'company.conversation.created' => 'Conversa criada',
            'company.conversation.assumed' => 'Conversa assumida',
            'company.conversation.released' => 'Conversa liberada',
            'company.conversation.transferred' => 'Conversa transferida',
            'company.conversation.closed' => 'Conversa encerrada',
            'company.conversation.manual_reply' => 'Resposta manual enviada',
            'company.conversation.send_template' => 'Template enviado',
            'company.conversation.contact_updated' => 'Contato atualizado',
            'company.conversation.tags_updated' => 'Tags da conversa atualizadas',
            'company.conversation.tag_attached' => 'Tag adicionada na conversa',
            'company.conversation.tag_detached' => 'Tag removida da conversa',
            'company.tag.created' => 'Tag criada',
            'company.tag.updated' => 'Tag atualizada',
            'company.tag.deleted' => 'Tag removida',
            'company.bot_settings.updated' => 'Configurações do bot atualizadas',
            'admin.company.bot_settings.updated' => 'Configurações do bot da empresa atualizadas',
            'admin.company.created' => 'Empresa criada',
            'admin.company.updated' => 'Empresa atualizada',
            'admin.company.deleted' => 'Empresa removida',
            'admin.user.created' => 'Usuário criado',
            'admin.user.updated' => 'Usuário atualizado',
            'admin.user.deleted' => 'Usuário removido',
            'admin.conversation.contact_updated' => 'Contato da conversa atualizado por admin',
            'admin.conversation.assume_blocked' => 'Ação bloqueada: assumir conversa',
            'admin.conversation.release_blocked' => 'Ação bloqueada: liberar conversa',
            'admin.conversation.manual_reply_blocked' => 'Ação bloqueada: resposta manual',
            'admin.conversation.close_blocked' => 'Ação bloqueada: encerrar conversa',
            'admin.conversation.tags_update_blocked' => 'Ação bloqueada: atualizar tags',
            'support.ticket.created' => 'Ticket de suporte criado',
            'support.ticket.message.created' => 'Mensagem de ticket enviada',
            'support.ticket.status_updated' => 'Status do ticket atualizado',
            'bot.simulation.executed' => 'Simulação do bot executada',
            'conversation.transferred' => 'Conversa transferida',
            'create_entity' => 'Registro criado',
            'update_entity' => 'Registro atualizado',
            'delete_entity' => 'Registro removido',
            'send_message' => 'Mensagem enviada',
        ];

        $normalized = trim($action);
        if ($normalized === '') {
            return 'Ação não informada';
        }

        if (isset($known[$normalized])) {
            return $known[$normalized];
        }

        $parts = collect(explode('.', $normalized))
            ->filter(fn (string $part): bool => trim($part) !== '')
            ->reject(fn (string $part): bool => in_array($part, ['company', 'admin', 'support', 'bot'], true))
            ->map(fn (string $part): string => str_replace('_', ' ', trim($part)))
            ->values()
            ->all();

        if ($parts === []) {
            return 'Ação não informada';
        }

        return Str::ucfirst(implode(' ', $parts));
    }

    private function decodeJsonField(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : $value;
        } catch (\Throwable) {
            return $value;
        }
    }
}



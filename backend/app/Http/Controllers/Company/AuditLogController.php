<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:120'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['required', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

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
            // Superadmin: pode ver todos os tenants visíveis e filtrar por empresa.
            if ($requestedCompanyId > 0) {
                $query->where('audit_logs.company_id', $requestedCompanyId);
            }

            return true;
        }

        if ($resellerId > 0) {
            // Reseller: apenas empresas vinculadas ao seu reseller_id.
            $query->where('audit_logs.reseller_id', $resellerId);
            if ($requestedCompanyId > 0) {
                $query->where('audit_logs.company_id', $requestedCompanyId);
            }

            return true;
        }

        if ($companyId > 0) {
            // Admin/usuário da empresa: sempre restrito ao próprio company_id.
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
}

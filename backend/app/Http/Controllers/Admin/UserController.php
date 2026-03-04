<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class UserController extends Controller
{
    public function __construct(
        private AuditLogService $auditLog
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->isSystemAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $summaryRows = User::query()
            ->selectRaw('COALESCE(company_id, 0) as company_scope, role, is_active, COUNT(*) as total')
            ->groupBy('company_scope', 'role', 'is_active')
            ->get();

        $companyIds = $summaryRows
            ->pluck('company_scope')
            ->map(fn($value) => (int) $value)
            ->filter(fn(int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $companyNames = Company::query()
            ->whereIn('id', $companyIds)
            ->pluck('name', 'id');

        return response()->json([
            'authenticated' => true,
            'privacy_mode' => 'blind_default',
            'users_summary' => $this->buildSummaryPayload($summaryRows, $companyNames),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->isSystemAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $this->auditLog->record($request, 'admin.user.create_blocked_by_privacy_mode');

        return response()->json([
            'message' => 'Operacao bloqueada para superadmin no modo privacidade.',
            'privacy_mode' => 'blind_default',
        ], 403);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->isSystemAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $this->auditLog->record(
            $request,
            'admin.user.update_blocked_by_privacy_mode',
            $user->company_id,
            ['target_user_id' => $user->id]
        );

        return response()->json([
            'message' => 'Operacao bloqueada para superadmin no modo privacidade.',
            'privacy_mode' => 'blind_default',
        ], 403);
    }

    /**
     * @param  Collection<int, object>  $summaryRows
     * @param  Collection<int, string>  $companyNames
     * @return array<string, mixed>
     */
    private function buildSummaryPayload(Collection $summaryRows, Collection $companyNames): array
    {
        $global = [
            'active' => 0,
            'inactive' => 0,
            'total' => 0,
            'by_role' => [],
        ];
        $companies = [];

        foreach ($summaryRows as $row) {
            $companyScope = (int) ($row->company_scope ?? 0);
            $normalizedRole = User::normalizeRole((string) ($row->role ?? ''));
            $isActive = (bool) ($row->is_active ?? false);
            $total = (int) ($row->total ?? 0);
            $statusKey = $isActive ? 'active' : 'inactive';

            $global[$statusKey] += $total;
            $global['total'] += $total;
            $global['by_role'][$normalizedRole] = (int) ($global['by_role'][$normalizedRole] ?? 0) + $total;

            if ($companyScope <= 0) {
                continue;
            }

            if (! isset($companies[$companyScope])) {
                $companies[$companyScope] = [
                    'company_id' => $companyScope,
                    'company_name' => $companyNames->get($companyScope, 'Empresa #'.$companyScope),
                    'active' => 0,
                    'inactive' => 0,
                    'total' => 0,
                    'by_role' => [],
                ];
            }

            $companies[$companyScope][$statusKey] += $total;
            $companies[$companyScope]['total'] += $total;
            $companies[$companyScope]['by_role'][$normalizedRole] =
                (int) ($companies[$companyScope]['by_role'][$normalizedRole] ?? 0) + $total;
        }

        ksort($companies);

        return [
            'global' => $global,
            'companies' => array_values($companies),
        ];
    }
}


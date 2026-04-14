<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeUserMail;
use App\Models\Area;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $users = User::query()
            ->with(['company:id,name', 'areas:id,name,company_id'])
            ->orderByRaw('CASE WHEN company_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('company_id')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'company_id', 'is_active', 'can_use_ai', 'disabled_at', 'created_at']);

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
            'users' => $users->map(fn(User $user) => $this->serializeUser($user))->values(),
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

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role' => ['required', Rule::in(User::assignableRoleValuesForSystemAdmin())],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'is_active' => ['sometimes', 'boolean'],
            'can_use_ai' => ['sometimes', 'boolean'],
            'area_ids' => ['sometimes', 'array', 'max:50'],
            'area_ids.*' => ['integer', 'exists:areas,id'],
            'areas' => ['sometimes', 'array', 'max:50'],
            'areas.*' => ['string', 'max:120'],
        ]);

        $normalizedRole = User::normalizeRole((string) $validated['role']);
        $companyId = $this->resolveCompanyIdForRole($normalizedRole, $validated['company_id'] ?? null);
        $areaIds = $companyId !== null ? $this->resolveAreaIdsForCompany($companyId, $validated) : [];
        $isActive = (bool) ($validated['is_active'] ?? true);
        $canUseAi = $this->resolveCanUseAi($normalizedRole, $validated);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $normalizedRole,
            'company_id' => $companyId,
            'is_active' => $isActive,
            'can_use_ai' => $canUseAi,
            'disabled_at' => $isActive ? null : now(),
        ]);

        $user->areas()->sync($areaIds);
        $user->load(['company:id,name', 'areas:id,name,company_id']);

        if ($isActive) {
            Mail::to($user->email)->queue(new WelcomeUserMail($user, (string) $validated['password']));
        }

        $this->auditLog->record($request, 'admin.user.created', $companyId, [
            'user_id' => $user->id,
            'role' => $user->role,
            'company_id' => $user->company_id,
            'is_active' => $user->is_active,
            'can_use_ai' => $user->can_use_ai,
            'area_ids' => $areaIds,
        ]);

        return response()->json([
            'ok' => true,
            'user' => $this->serializeUser($user),
        ], 201);
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

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(User::assignableRoleValuesForSystemAdmin())],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'is_active' => ['required', 'boolean'],
            'can_use_ai' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'area_ids' => ['sometimes', 'array', 'max:50'],
            'area_ids.*' => ['integer', 'exists:areas,id'],
            'areas' => ['sometimes', 'array', 'max:50'],
            'areas.*' => ['string', 'max:120'],
        ]);

        $normalizedRole = User::normalizeRole((string) $validated['role']);
        $companyId = $this->resolveCompanyIdForRole($normalizedRole, $validated['company_id'] ?? null);
        $areaIds = $companyId !== null ? $this->resolveAreaIdsForCompany($companyId, $validated) : [];
        $isActive = (bool) $validated['is_active'];
        $canUseAi = $this->resolveCanUseAi($normalizedRole, $validated, $user);

        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'company_id' => $user->company_id,
            'is_active' => $user->is_active,
            'can_use_ai' => (bool) $user->can_use_ai,
            'disabled_at' => $user->disabled_at,
            'area_ids' => $user->areas()->pluck('areas.id')->map(fn($id) => (int) $id)->values()->all(),
        ];

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $normalizedRole;
        $user->company_id = $companyId;
        $user->is_active = $isActive;
        $user->can_use_ai = $canUseAi;
        $user->disabled_at = $isActive ? null : ($user->disabled_at ?? now());
        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }
        $user->save();

        $user->areas()->sync($areaIds);
        $user->load(['company:id,name', 'areas:id,name,company_id']);

        $this->auditLog->record($request, 'admin.user.updated', $companyId, [
            'target_user_id' => $user->id,
            'before' => $before,
            'after' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'company_id' => $user->company_id,
                'is_active' => $user->is_active,
                'can_use_ai' => (bool) $user->can_use_ai,
                'disabled_at' => $user->disabled_at,
                'area_ids' => $areaIds,
            ],
        ]);

        return response()->json([
            'ok' => true,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->isSystemAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        if ((int) $actor->id === (int) $user->id) {
            return response()->json([
                'message' => 'Você não pode excluir o próprio usuário.',
            ], 422);
        }

        $companyId = $user->company_id ? (int) $user->company_id : null;

        $userId = $user->id;
        $user->delete();

        $this->auditLog->record($request, 'admin.user.deleted', $companyId, [
            'target_user_id' => $userId,
        ]);

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @param  mixed  $rawCompanyId
     */
    private function resolveCompanyIdForRole(string $normalizedRole, mixed $rawCompanyId): ?int
    {
        if ($normalizedRole === User::ROLE_SYSTEM_ADMIN) {
            return null;
        }

        $companyId = (int) $rawCompanyId;
        if ($companyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => ['company_id obrigatorio para esse perfil.'],
            ]);
        }

        return $companyId;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     */
    private function resolveAreaIdsForCompany(int $companyId, array $validated): array
    {
        $ids = collect($validated['area_ids'] ?? [])
            ->map(fn($value) => (int) $value)
            ->filter(fn(int $value) => $value > 0)
            ->values()
            ->all();

        $names = collect($validated['areas'] ?? [])
            ->map(fn($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        if ($names !== []) {
            $resolvedByName = Area::query()
                ->where('company_id', $companyId)
                ->whereIn('name', $names)
                ->pluck('id')
                ->map(fn($value) => (int) $value)
                ->values()
                ->all();

            if (count($resolvedByName) !== count(array_unique($names))) {
                throw ValidationException::withMessages([
                    'areas' => ['Uma ou mais areas informadas nao existem para a empresa.'],
                ]);
            }

            $ids = array_merge($ids, $resolvedByName);
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $validAreaCount = Area::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->count();

        if ($validAreaCount !== count($ids)) {
            throw ValidationException::withMessages([
                'area_ids' => ['Area informada nao pertence a empresa.'],
            ]);
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveCanUseAi(string $normalizedRole, array $validated, ?User $currentUser = null): bool
    {
        if ($normalizedRole !== User::ROLE_AGENT) {
            return true;
        }

        if (array_key_exists('can_use_ai', $validated)) {
            return (bool) $validated['can_use_ai'];
        }

        return (bool) ($currentUser?->can_use_ai ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::normalizeRole($user->role),
            'company_id' => $user->company_id,
            'is_active' => (bool) $user->is_active,
            'can_use_ai' => (bool) $user->can_use_ai,
            'disabled_at' => $user->disabled_at,
            'company' => $user->company ? [
                'id' => $user->company->id,
                'name' => $user->company->name,
            ] : null,
            'area_ids' => $user->areas->pluck('id')->map(fn($id) => (int) $id)->values()->all(),
            'areas' => $user->areas->pluck('name')->values()->all(),
            'areas_detail' => $user->areas->map(fn(Area $area) => [
                'id' => $area->id,
                'name' => $area->name,
                'company_id' => $area->company_id,
            ])->values()->all(),
            'created_at' => $user->created_at,
        ];
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

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Mail\WelcomeUserMail;
use App\Models\Area;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(
        private AuditLogService $auditLog
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $actorIsSystemAdmin = (bool) $actor?->isSystemAdmin();
        $actorResellerId = $this->resolveActorResellerId($request);

        $usersQuery = User::query()
            ->with(['company:id,name,reseller_id', 'reseller:id,name', 'areas:id,name,company_id'])
            ->orderByRaw('CASE WHEN company_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('company_id')
            ->orderBy('name');
        $this->applyActorScopeToUsersQuery($usersQuery, $actorResellerId);
        if ($actorIsSystemAdmin) {
            $usersQuery->whereIn('role', [
                User::ROLE_SYSTEM_ADMIN,
                User::ROLE_RESELLER_ADMIN,
                User::ROLE_LEGACY_ADMIN,
            ]);
        }
        $users = $usersQuery->get(['id', 'name', 'email', 'role', 'company_id', 'reseller_id', 'is_active', 'can_use_ai', 'disabled_at', 'created_at']);

        $summaryQuery = User::query()
            ->selectRaw('COALESCE(company_id, 0) as company_scope, role, is_active, COUNT(*) as total')
            ->groupBy('company_scope', 'role', 'is_active');
        $this->applyActorScopeToUsersQuery($summaryQuery, $actorResellerId);
        if ($actorIsSystemAdmin) {
            $summaryQuery->whereIn('role', [
                User::ROLE_SYSTEM_ADMIN,
                User::ROLE_RESELLER_ADMIN,
                User::ROLE_LEGACY_ADMIN,
            ]);
        }
        $summaryRows = $summaryQuery->get();

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

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $actor = $request->user();
        $actorIsSystemAdmin = (bool) $actor?->isSystemAdmin();
        $actorResellerId = $this->resolveActorResellerId($request);

        $normalizedRole = User::normalizeRole((string) $validated['role']);
        if ($actorIsSystemAdmin && ! $this->isRoleManagedBySystemAdmin($normalizedRole)) {
            return response()->json(['message' => 'Superadmin pode gerenciar apenas superadmins e admins de revenda.'], 403);
        }
        $tenantScope = $this->resolveTenantScopeForRole(
            $normalizedRole,
            $validated['company_id'] ?? null,
            $validated['reseller_id'] ?? null
        );
        $companyId = $tenantScope['company_id'];
        $resellerId = $tenantScope['reseller_id'];

        if ($actorResellerId !== null) {
            if ($normalizedRole === User::ROLE_SYSTEM_ADMIN) {
                return response()->json(['message' => 'Acesso negado para criar superadmin.'], 403);
            }

            if ($companyId !== null) {
                $companyResellerId = (int) (Company::query()->where('id', $companyId)->value('reseller_id') ?? 0);
                if ($companyResellerId !== $actorResellerId) {
                    return response()->json(['message' => 'Acesso negado para esta empresa.'], 403);
                }
            }

            if ($normalizedRole === User::ROLE_RESELLER_ADMIN && (int) ($resellerId ?? 0) !== $actorResellerId) {
                return response()->json(['message' => 'Acesso negado para este reseller.'], 403);
            }
        }
        $areaIds = $companyId !== null ? $this->resolveAreaIdsForCompany($companyId, $validated) : [];
        $isActive = (bool) ($validated['is_active'] ?? true);
        $canUseAi = $this->resolveCanUseAi($normalizedRole, $validated);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $normalizedRole,
            'company_id' => $companyId,
            'reseller_id' => $resellerId,
            'is_active' => $isActive,
            'can_use_ai' => $canUseAi,
            'disabled_at' => $isActive ? null : now(),
        ]);

        $user->areas()->sync($areaIds);
        $user->load(['company:id,name,reseller_id', 'reseller:id,name', 'areas:id,name,company_id']);

        if ($isActive) {
            Mail::to($user->email)->queue(new WelcomeUserMail($user, (string) $validated['password']));
        }

        $this->auditLog->record($request, 'admin.user.created', $companyId, [
            'user_id' => $user->id,
            'role' => $user->role,
            'company_id' => $user->company_id,
            'reseller_id' => $user->reseller_id,
            'is_active' => $user->is_active,
            'can_use_ai' => $user->can_use_ai,
            'area_ids' => $areaIds,
        ]);

        return response()->json([
            'ok' => true,
            'user' => $this->serializeUser($user),
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();
        $actor = $request->user();
        $actorIsSystemAdmin = (bool) $actor?->isSystemAdmin();
        $actorResellerId = $this->resolveActorResellerId($request);
        if ($actorIsSystemAdmin && ! $this->isRoleManagedBySystemAdmin(User::normalizeRole($user->role))) {
            return response()->json(['message' => 'Superadmin pode editar apenas superadmins e admins de revenda.'], 403);
        }

        if ($actorResellerId !== null && ! $this->userBelongsToResellerScope($user, $actorResellerId)) {
            return response()->json(['message' => 'Acesso negado para este usuário.'], 403);
        }

        $normalizedRole = User::normalizeRole((string) $validated['role']);
        if ($actorIsSystemAdmin && ! $this->isRoleManagedBySystemAdmin($normalizedRole)) {
            return response()->json(['message' => 'Superadmin pode gerenciar apenas superadmins e admins de revenda.'], 403);
        }
        $tenantScope = $this->resolveTenantScopeForRole(
            $normalizedRole,
            $validated['company_id'] ?? null,
            $validated['reseller_id'] ?? null
        );
        $companyId = $tenantScope['company_id'];
        $resellerId = $tenantScope['reseller_id'];

        if ($actorResellerId !== null) {
            if ($normalizedRole === User::ROLE_SYSTEM_ADMIN) {
                return response()->json(['message' => 'Acesso negado para promover a superadmin.'], 403);
            }

            if ($companyId !== null) {
                $companyResellerId = (int) (Company::query()->where('id', $companyId)->value('reseller_id') ?? 0);
                if ($companyResellerId !== $actorResellerId) {
                    return response()->json(['message' => 'Acesso negado para esta empresa.'], 403);
                }
            }

            if ($normalizedRole === User::ROLE_RESELLER_ADMIN && (int) ($resellerId ?? 0) !== $actorResellerId) {
                return response()->json(['message' => 'Acesso negado para este reseller.'], 403);
            }
        }
        $areaIds = $companyId !== null ? $this->resolveAreaIdsForCompany($companyId, $validated) : [];
        $isActive = (bool) $validated['is_active'];
        $canUseAi = $this->resolveCanUseAi($normalizedRole, $validated, $user);

        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'company_id' => $user->company_id,
            'reseller_id' => $user->reseller_id,
            'is_active' => $user->is_active,
            'can_use_ai' => (bool) $user->can_use_ai,
            'disabled_at' => $user->disabled_at,
            'area_ids' => $user->areas()->pluck('areas.id')->map(fn($id) => (int) $id)->values()->all(),
        ];

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $normalizedRole;
        $user->company_id = $companyId;
        $user->reseller_id = $resellerId;
        $user->is_active = $isActive;
        $user->can_use_ai = $canUseAi;
        $user->disabled_at = $isActive ? null : ($user->disabled_at ?? now());
        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }
        $user->save();

        $user->areas()->sync($areaIds);
        $user->load(['company:id,name,reseller_id', 'reseller:id,name', 'areas:id,name,company_id']);

        $this->auditLog->record($request, 'admin.user.updated', $companyId, [
            'target_user_id' => $user->id,
            'before' => $before,
            'after' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'company_id' => $user->company_id,
                'reseller_id' => $user->reseller_id,
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
        $actorIsSystemAdmin = (bool) $actor?->isSystemAdmin();
        $actorResellerId = $this->resolveActorResellerId($request);

        if ((int) $actor->id === (int) $user->id) {
            return response()->json([
                'message' => 'Você não pode excluir o próprio usuário.',
            ], 422);
        }

        if ($actorResellerId !== null && ! $this->userBelongsToResellerScope($user, $actorResellerId)) {
            return response()->json(['message' => 'Acesso negado para este usuário.'], 403);
        }

        if ($actorIsSystemAdmin && ! $this->isRoleManagedBySystemAdmin(User::normalizeRole($user->role))) {
            return response()->json(['message' => 'Superadmin pode excluir apenas superadmins e admins de revenda.'], 403);
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

    private function resolveActorResellerId(Request $request): ?int
    {
        $actor = $request->user();
        if (! $actor || $actor->isSystemAdmin()) {
            return null;
        }

        $resellerId = (int) ($actor->reseller_id ?? $actor->company?->reseller_id ?? 0);
        return $resellerId > 0 ? $resellerId : -1;
    }

    private function applyActorScopeToUsersQuery($query, ?int $actorResellerId): void
    {
        if ($actorResellerId === null) {
            return;
        }

        $query->where(function ($scope) use ($actorResellerId) {
            $scope->where('users.reseller_id', $actorResellerId)
                ->orWhereHas('company', fn ($companyQuery) => $companyQuery->where('reseller_id', $actorResellerId));
        });
    }

    private function userBelongsToResellerScope(User $user, int $actorResellerId): bool
    {
        $directResellerId = (int) ($user->reseller_id ?? 0);
        if ($directResellerId > 0) {
            return $directResellerId === $actorResellerId;
        }

        $companyResellerId = (int) ($user->company?->reseller_id
            ?? Company::query()->where('id', (int) ($user->company_id ?? 0))->value('reseller_id')
            ?? 0);

        return $companyResellerId > 0 && $companyResellerId === $actorResellerId;
    }

    private function isRoleManagedBySystemAdmin(string $normalizedRole): bool
    {
        return in_array($normalizedRole, [
            User::ROLE_SYSTEM_ADMIN,
            User::ROLE_RESELLER_ADMIN,
        ], true);
    }

    private function resolveTenantScopeForRole(string $normalizedRole, mixed $rawCompanyId, mixed $rawResellerId): array
    {
        if ($normalizedRole === User::ROLE_SYSTEM_ADMIN) {
            return [
                'company_id' => null,
                'reseller_id' => null,
            ];
        }

        $companyId = (int) $rawCompanyId;

        if ($normalizedRole === User::ROLE_RESELLER_ADMIN) {
            $resellerId = (int) $rawResellerId;

            if ($resellerId <= 0 && $companyId > 0) {
                $resellerId = (int) (Company::query()->where('id', $companyId)->value('reseller_id') ?? 0);
            }

            if ($resellerId <= 0) {
                throw ValidationException::withMessages([
                    'reseller_id' => ['reseller_id obrigatorio para admin de revenda.'],
                ]);
            }

            if ($companyId > 0) {
                $companyResellerId = (int) (Company::query()->where('id', $companyId)->value('reseller_id') ?? 0);
                if ($companyResellerId !== $resellerId) {
                    throw ValidationException::withMessages([
                        'company_id' => ['company_id nao pertence ao reseller informado.'],
                    ]);
                }
            } else {
                $companyId = null;
            }

            return [
                'company_id' => $companyId,
                'reseller_id' => $resellerId,
            ];
        }

        if ($companyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => ['company_id obrigatório para esse perfil.'],
            ]);
        }

        return [
            'company_id' => $companyId,
            'reseller_id' => null,
        ];
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
                    'areas' => ['Uma ou mais areas informadas não existem para a empresa.'],
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
                'area_ids' => ['Area informada não pertence a empresa.'],
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
            'reseller_id' => $user->reseller_id,
            'is_active' => (bool) $user->is_active,
            'can_use_ai' => (bool) $user->can_use_ai,
            'disabled_at' => $user->disabled_at,
            'company' => $user->company ? [
                'id' => $user->company->id,
                'name' => $user->company->name,
            ] : null,
            'reseller' => $user->reseller ? [
                'id' => $user->reseller->id,
                'name' => $user->reseller->name,
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


<?php

namespace App\Services\Admin;

use App\Models\Area;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AdminUserManagementSupportService
{
    public function resolveActorResellerId(Request $request): ?int
    {
        $actor = $request->user();
        if (! $actor || $actor->isSystemAdmin()) {
            return null;
        }

        $resellerId = (int) ($actor->reseller_id ?? $actor->company?->reseller_id ?? 0);
        return $resellerId > 0 ? $resellerId : -1;
    }

    public function applyActorScopeToUsersQuery($query, ?int $actorResellerId): void
    {
        if ($actorResellerId === null) {
            return;
        }

        $query->where(function ($scope) use ($actorResellerId) {
            $scope->where('users.reseller_id', $actorResellerId)
                ->orWhereHas('company', fn ($companyQuery) => $companyQuery->where('reseller_id', $actorResellerId));
        });
    }

    public function userBelongsToResellerScope(User $user, int $actorResellerId): bool
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

    public function isRoleManagedBySystemAdmin(string $normalizedRole): bool
    {
        return in_array($normalizedRole, [
            User::ROLE_SYSTEM_ADMIN,
            User::ROLE_RESELLER_ADMIN,
        ], true);
    }

    /**
     * @return array{company_id: int|null, reseller_id: int|null}
     */
    public function resolveTenantScopeForRole(string $normalizedRole, mixed $rawCompanyId, mixed $rawResellerId): array
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
                'company_id' => ['company_id obrigatorio para esse perfil.'],
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
    public function resolveAreaIdsForCompany(int $companyId, array $validated): array
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
    public function resolveCanUseAi(string $normalizedRole, array $validated, ?User $currentUser = null): bool
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
    public function serializeUser(User $user): array
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
    public function buildSummaryPayload(Collection $summaryRows, Collection $companyNames): array
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

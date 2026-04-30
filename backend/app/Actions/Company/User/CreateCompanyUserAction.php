<?php

declare(strict_types=1);


namespace App\Actions\Company\User;

use App\Data\ActionResponse;
use App\Models\AppointmentStaffProfile;
use App\Models\Area;
use App\Models\User;
use App\Services\Company\CompanyUsageLimitsService;
use App\Services\ProductMetricsService;
use App\Support\ProductFunnels;
use App\Support\UserPermissions;
use Illuminate\Validation\ValidationException;

class CreateCompanyUserAction
{
    public function __construct(
        private readonly CompanyUsageLimitsService $usageLimits,
        private readonly ProductMetricsService $productMetrics,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(int $companyId, array $validated, ?User $actor = null): ActionResponse
    {
        $limitCheck = $this->usageLimits->checkUserLimit($companyId);
        if (! $limitCheck->allowed) {
            return $limitCheck->toBlockedResponse(['current' => $limitCheck->count]);
        }

        $normalizedRole = User::normalizeRole((string) $validated['role']);
        $areaIds        = $this->resolveAreaIds($companyId, $validated);
        $isActive       = (bool) ($validated['is_active'] ?? true);
        $canUseAi       = $this->resolveCanUseAi($normalizedRole, $validated);
        $permissions    = $this->resolvePermissions($normalizedRole, $validated);

        $user = User::create([
            'name'        => $validated['name'],
            'email'       => $validated['email'],
            'password'    => $validated['password'],
            'role'        => $normalizedRole,
            'company_id'  => $companyId,
            'is_active'   => $isActive,
            'can_use_ai'  => $canUseAi,
            'disabled_at' => $isActive ? null : now(),
            'permissions' => $permissions,
        ]);

        $user->areas()->sync($areaIds);
        $this->syncAppointmentProfile($companyId, $user, $validated);

        $this->productMetrics->track(
            ProductFunnels::CADASTRO,
            'user_created',
            'company_user_created',
            $companyId,
            (int) $user->id,
            [
                'created_by_user_id' => $actor?->id ? (int) $actor->id : null,
                'role' => User::normalizeRole((string) $user->role),
                'is_active' => (bool) $user->is_active,
            ],
        );

        return ActionResponse::created(array_merge(
            ['ok' => true, 'user' => $user, 'current_users' => $limitCheck->count, 'max_users' => $limitCheck->limit],
            $limitCheck->warningPayload()
        ));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     */
    public function resolveAreaIds(int $companyId, array $validated): array
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
     * @return list<string>|null
     */
    public function resolvePermissions(string $normalizedRole, array $validated, ?User $currentUser = null): ?array
    {
        if ($normalizedRole !== User::ROLE_AGENT) {
            return null;
        }

        if (array_key_exists('permissions', $validated)) {
            return UserPermissions::sanitize($validated['permissions']);
        }

        return $currentUser?->permissions;
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
     * @param  array<string, mixed>  $validated
     */
    public function syncAppointmentProfile(int $companyId, User $user, array $validated): void
    {
        if (! array_key_exists('appointment_is_staff', $validated) && ! array_key_exists('appointment_display_name', $validated)) {
            return;
        }

        try {
            $profile = AppointmentStaffProfile::query()->firstOrCreate(
                ['company_id' => $companyId, 'user_id' => (int) $user->id],
                ['display_name' => $user->name, 'is_bookable' => true]
            );

            if (array_key_exists('appointment_is_staff', $validated)) {
                $profile->is_bookable = (bool) $validated['appointment_is_staff'];
            }
            if (array_key_exists('appointment_display_name', $validated)) {
                $text = trim((string) ($validated['appointment_display_name'] ?? ''));
                $profile->display_name = $text !== '' ? $text : null;
            }

            $profile->save();
        } catch (\Throwable) {
            // Tabela pode não existir ainda em produção
        }
    }
}

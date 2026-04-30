<?php

declare(strict_types=1);


namespace App\Actions\Company\User;

use App\Models\User;

class UpdateCompanyUserAction
{
    public function __construct(private readonly CreateCompanyUserAction $createAction) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(int $companyId, User $user, array $validated): void
    {
        $normalizedRole = User::normalizeRole((string) $validated['role']);
        $areaIds        = $this->createAction->resolveAreaIds($companyId, $validated);
        $isActive       = (bool) $validated['is_active'];
        $canUseAi       = $this->createAction->resolveCanUseAi($normalizedRole, $validated, $user);
        $permissions    = $this->createAction->resolvePermissions($normalizedRole, $validated, $user);

        $user->name        = $validated['name'];
        $user->email       = $validated['email'];
        $user->role        = $normalizedRole;
        $user->is_active   = $isActive;
        $user->can_use_ai  = $canUseAi;
        $user->permissions = $permissions;
        $user->disabled_at = $isActive ? null : ($user->disabled_at ?? now());

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();
        $user->areas()->sync($areaIds);
        $this->createAction->syncAppointmentProfile($companyId, $user, $validated);
    }
}

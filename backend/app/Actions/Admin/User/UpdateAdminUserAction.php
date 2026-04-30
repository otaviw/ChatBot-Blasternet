<?php

declare(strict_types=1);


namespace App\Actions\Admin\User;

use App\Data\ActionResponse;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\Admin\AdminUserManagementSupportService;
use App\Services\AuditLogService;

class UpdateAdminUserAction
{
    public function __construct(
        private readonly AdminUserManagementSupportService $support,
        private readonly AuditLogService $auditLog
    ) {}

    public function handle(UpdateUserRequest $request, User $user): ActionResponse
    {
        $validated = $request->validated();
        $actor = $request->user();
        $actorIsSystemAdmin = (bool) $actor?->isSystemAdmin();
        $actorResellerId = $this->support->resolveActorResellerId($request);

        if ($actorIsSystemAdmin && ! $this->support->isRoleManagedBySystemAdmin(User::normalizeRole($user->role))) {
            return ActionResponse::forbidden('Superadmin pode editar apenas superadmins e admins de revenda.');
        }

        if ($actorResellerId !== null && ! $this->support->userBelongsToResellerScope($user, $actorResellerId)) {
            return ActionResponse::forbidden('Acesso negado para este usuario.');
        }

        $normalizedRole = User::normalizeRole((string) $validated['role']);
        if ($actorIsSystemAdmin && ! $this->support->isRoleManagedBySystemAdmin($normalizedRole)) {
            return ActionResponse::forbidden('Superadmin pode gerenciar apenas superadmins e admins de revenda.');
        }

        $tenantScope = $this->support->resolveTenantScopeForRole(
            $normalizedRole,
            $validated['company_id'] ?? null,
            $validated['reseller_id'] ?? null
        );
        $companyId = $tenantScope['company_id'];
        $resellerId = $tenantScope['reseller_id'];

        if ($actorResellerId !== null) {
            if ($normalizedRole === User::ROLE_SYSTEM_ADMIN) {
                return ActionResponse::forbidden('Acesso negado para promover a superadmin.');
            }

            if ($companyId !== null) {
                $companyResellerId = (int) (Company::query()->where('id', $companyId)->value('reseller_id') ?? 0);
                if ($companyResellerId !== $actorResellerId) {
                    return ActionResponse::forbidden('Acesso negado para esta empresa.');
                }
            }

            if ($normalizedRole === User::ROLE_RESELLER_ADMIN && (int) ($resellerId ?? 0) !== $actorResellerId) {
                return ActionResponse::forbidden('Acesso negado para este reseller.');
            }
        }

        $areaIds = $companyId !== null ? $this->support->resolveAreaIdsForCompany($companyId, $validated) : [];
        $isActive = (bool) $validated['is_active'];
        $canUseAi = $this->support->resolveCanUseAi($normalizedRole, $validated, $user);

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

        return ActionResponse::ok(['ok' => true, 'user' => $this->support->serializeUser($user)]);
    }
}

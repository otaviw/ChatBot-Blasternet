<?php

namespace App\Actions\Admin\User;

use App\Data\ActionResponse;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Mail\WelcomeUserMail;
use App\Models\Company;
use App\Models\User;
use App\Services\Admin\AdminUserManagementSupportService;
use App\Services\AuditLogService;
use App\Services\ProductMetricsService;
use App\Support\ProductFunnels;
use Illuminate\Support\Facades\Mail;

class StoreAdminUserAction
{
    public function __construct(
        private readonly AdminUserManagementSupportService $support,
        private readonly AuditLogService $auditLog,
        private readonly ProductMetricsService $productMetrics,
    ) {}

    public function handle(StoreUserRequest $request): ActionResponse
    {
        $validated = $request->validated();
        $actor = $request->user();
        $actorIsSystemAdmin = (bool) $actor?->isSystemAdmin();
        $actorResellerId = $this->support->resolveActorResellerId($request);

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
                return ActionResponse::forbidden('Acesso negado para criar superadmin.');
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
        $isActive = (bool) ($validated['is_active'] ?? true);
        $canUseAi = $this->support->resolveCanUseAi($normalizedRole, $validated);

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

        $this->productMetrics->track(
            ProductFunnels::CADASTRO,
            'user_created',
            'admin_user_created',
            $user->company_id ? (int) $user->company_id : null,
            (int) $user->id,
            [
                'created_by_user_id' => $actor?->id ? (int) $actor->id : null,
                'role' => User::normalizeRole((string) $user->role),
                'is_active' => (bool) $user->is_active,
            ],
        );

        return ActionResponse::created(['ok' => true, 'user' => $this->support->serializeUser($user)]);
    }
}

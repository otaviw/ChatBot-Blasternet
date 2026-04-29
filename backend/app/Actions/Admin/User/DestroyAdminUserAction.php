<?php

namespace App\Actions\Admin\User;

use App\Data\ActionResponse;
use App\Models\User;
use App\Services\Admin\AdminUserManagementSupportService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

class DestroyAdminUserAction
{
    public function __construct(
        private readonly AdminUserManagementSupportService $support,
        private readonly AuditLogService $auditLog
    ) {}

    public function handle(Request $request, User $user): ActionResponse
    {
        $actor = $request->user();
        $actorIsSystemAdmin = (bool) $actor?->isSystemAdmin();
        $actorResellerId = $this->support->resolveActorResellerId($request);

        if ((int) $actor->id === (int) $user->id) {
            return ActionResponse::unprocessable('Voce nao pode excluir o proprio usuario.');
        }

        if ($actorResellerId !== null && ! $this->support->userBelongsToResellerScope($user, $actorResellerId)) {
            return ActionResponse::forbidden('Acesso negado para este usuario.');
        }

        if ($actorIsSystemAdmin && ! $this->support->isRoleManagedBySystemAdmin(User::normalizeRole($user->role))) {
            return ActionResponse::forbidden('Superadmin pode excluir apenas superadmins e admins de revenda.');
        }

        $companyId = $user->company_id ? (int) $user->company_id : null;
        $userId = $user->id;
        $user->delete();

        $this->auditLog->record($request, 'admin.user.deleted', $companyId, [
            'target_user_id' => $userId,
        ]);

        return ActionResponse::ok(['ok' => true]);
    }
}

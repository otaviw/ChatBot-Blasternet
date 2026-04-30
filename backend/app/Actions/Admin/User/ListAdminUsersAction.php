<?php

declare(strict_types=1);


namespace App\Actions\Admin\User;

use App\Models\Company;
use App\Models\User;
use App\Services\Admin\AdminUserManagementSupportService;
use Illuminate\Http\Request;

class ListAdminUsersAction
{
    public function __construct(
        private readonly AdminUserManagementSupportService $support
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request): array
    {
        $actor = $request->user();
        $actorIsSystemAdmin = (bool) $actor?->isSystemAdmin();
        $actorResellerId = $this->support->resolveActorResellerId($request);

        $usersQuery = User::query()
            ->with(['company:id,name,reseller_id', 'reseller:id,name', 'areas:id,name,company_id'])
            ->orderByRaw('CASE WHEN company_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('company_id')
            ->orderBy('name');
        $this->support->applyActorScopeToUsersQuery($usersQuery, $actorResellerId);
        if ($actorIsSystemAdmin) {
            $usersQuery->whereIn('role', [
                User::ROLE_SYSTEM_ADMIN,
                User::ROLE_RESELLER_ADMIN,
            ]);
        }
        $users = $usersQuery->get(['id', 'name', 'email', 'role', 'company_id', 'reseller_id', 'is_active', 'can_use_ai', 'disabled_at', 'created_at']);

        $summaryQuery = User::query()
            ->selectRaw('COALESCE(company_id, 0) as company_scope, role, is_active, COUNT(*) as total')
            ->groupBy('company_scope', 'role', 'is_active');
        $this->support->applyActorScopeToUsersQuery($summaryQuery, $actorResellerId);
        if ($actorIsSystemAdmin) {
            $summaryQuery->whereIn('role', [
                User::ROLE_SYSTEM_ADMIN,
                User::ROLE_RESELLER_ADMIN,
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

        return [
            'authenticated' => true,
            'users' => $users->map(fn(User $user) => $this->support->serializeUser($user))->values(),
            'users_summary' => $this->support->buildSummaryPayload($summaryRows, $companyNames),
        ];
    }
}

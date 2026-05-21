<?php

declare(strict_types=1);


namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ProductEvent;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function __construct(
        private readonly AiAccessService $aiAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'authenticated' => Auth::check(),
            'demo_accounts' => [
                [
                    'label' => 'Admin demo',
                    'email' => 'admin@teste.local',
                    'password' => 'teste123',
                ],
                [
                    'label' => 'Empresa demo',
                    'email' => 'empresa@teste.local',
                    'password' => 'teste123',
                ],
            ],
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $user->loadMissing('company.reseller', 'reseller');
        $normalizedRole = User::normalizeRole($user->role);
        $settings = $this->aiAccessService->resolveCompanySettings($user);
        $canUseInternalAi = $this->aiAccessService->canUseInternalAi($user, $settings);
        $basePayload = [
            'authenticated' => true,
            'user_role' => $normalizedRole,
            'can_manage_users' => $user->canManageCompanyUsers(),
            'can_manage_ai' => $this->aiAccessService->canManageAi($user),
            'can_use_ai' => $canUseInternalAi,
            'can_access_internal_ai_chat' => $canUseInternalAi,
            'permissions' => $user->resolvedPermissions(),
            'last_access_at' => $this->lastAccessAt($user),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $normalizedRole,
                'company_id' => $user->company_id,
                'company_name' => $user->company?->name,
            ],
            'user_summary' => $this->userSummary($user),
        ];

        if ($user->isAdmin()) {
            return response()->json($basePayload + [
                'role' => 'admin',
                'can_manage_users' => false,
                'reseller' => $user->reseller ? [
                    'id' => $user->reseller->id,
                    'name' => $user->reseller->name,
                    'logo_url' => $user->reseller->logo_url,
                ] : null,
            ]);
        }

        $company = $user->company;

        $payload = $basePayload + [
            'role' => 'company',
            'companyName' => $company?->name ?? 'Empresa',
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'logo_url' => null,
                'reseller_logo_url' => $company->reseller?->logo_url,
                'configuration' => [
                    'has_meta_credentials' => (bool) $company->has_meta_credentials,
                    'has_ixc_integration' => (bool) $company->has_ixc_integration,
                    'has_ixc_credentials' => (bool) $company->has_ixc_credentials,
                    'has_appointment_setup' => $this->hasAppointmentSetup((int) $company->id),
                ],
            ] : null,
            'has_ixc_integration' => (bool) ($company?->has_ixc_integration ?? false),
        ];

        if ($user->isCompanyAdmin() && $company) {
            $payload['company_summary'] = $this->companySummary((int) $company->id);
        }

        return response()->json($payload);
    }

    private function lastAccessAt(User $user): ?string
    {
        $lastLogin = ProductEvent::query()
            ->where('user_id', $user->id)
            ->where('event_name', 'auth_login_success')
            ->latest('occurred_at')
            ->first(['occurred_at']);

        return $lastLogin?->occurred_at?->toISOString() ?? $user->updated_at?->toISOString();
    }

    /**
     * @return array<string, int>
     */
    private function userSummary(User $user): array
    {
        $todayStart = now()->startOfDay();
        $weekStart = now()->subDays(7);

        $actionsBase = ProductEvent::query()->where('user_id', $user->id);

        $assignedConversations = Conversation::query()
            ->where('status', 'open')
            ->where(function ($query) use ($user): void {
                $query
                    ->where('assigned_user_id', $user->id)
                    ->orWhere(function ($subQuery) use ($user): void {
                        $subQuery
                            ->where('assigned_type', 'user')
                            ->where('assigned_id', $user->id);
                    });
            })
            ->count();

        $assignedTickets = SupportTicket::query()
            ->where('status', SupportTicket::STATUS_OPEN)
            ->where('managed_by_user_id', $user->id)
            ->count();

        return [
            'actions_today' => (clone $actionsBase)->where('occurred_at', '>=', $todayStart)->count(),
            'actions_week' => (clone $actionsBase)->where('occurred_at', '>=', $weekStart)->count(),
            'assigned_pending' => $assignedConversations + $assignedTickets,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function companySummary(int $companyId): array
    {
        $weekStart = now()->subDays(7);

        $criticalTickets = SupportTicket::query()
            ->where('company_id', $companyId)
            ->where('status', SupportTicket::STATUS_OPEN)
            ->count();

        $unassignedConversations = Conversation::query()
            ->where('company_id', $companyId)
            ->where('status', 'open')
            ->where(function ($query): void {
                $query
                    ->whereNull('assigned_user_id')
                    ->where(function ($subQuery): void {
                        $subQuery->whereNull('assigned_type')->orWhere('assigned_type', '!=', 'user');
                    });
            })
            ->count();

        return [
            'active_users_7d' => $this->activeCompanyUsersLastSevenDays($companyId),
            'total_users' => User::query()->where('company_id', $companyId)->count(),
            'core_metric' => [
                'label' => 'Conversas criadas',
                'period' => '7 dias',
                'value' => Conversation::query()
                    ->where('company_id', $companyId)
                    ->where('created_at', '>=', $weekStart)
                    ->count(),
            ],
            'critical_pending' => $criticalTickets + $unassignedConversations,
        ];
    }

    private function activeCompanyUsersLastSevenDays(int $companyId): int
    {
        if (! Schema::hasTable('sessions')) {
            return 0;
        }

        return DB::table('sessions')
            ->join('users', 'users.id', '=', 'sessions.user_id')
            ->where('users.company_id', $companyId)
            ->where('sessions.last_activity', '>=', now()->subDays(7)->timestamp)
            ->distinct('sessions.user_id')
            ->count('sessions.user_id');
    }

    private function hasAppointmentSetup(int $companyId): bool
    {
        if (
            ! Schema::hasTable('appointment_settings')
            || ! Schema::hasTable('appointment_services')
            || ! Schema::hasTable('appointment_staff_profiles')
        ) {
            return false;
        }

        return DB::table('appointment_settings')->where('company_id', $companyId)->exists()
            && DB::table('appointment_services')->where('company_id', $companyId)->where('is_active', true)->exists()
            && DB::table('appointment_staff_profiles')->where('company_id', $companyId)->where('is_bookable', true)->exists();
    }
}

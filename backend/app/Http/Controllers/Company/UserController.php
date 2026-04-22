<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreUserRequest;
use App\Http\Requests\Company\UpdateUserRequest;
use App\Models\Area;
use App\Models\AppointmentStaffProfile;
use App\Models\User;
use App\Services\Company\CompanyUsageLimitsService;
use App\Support\UserPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private CompanyUsageLimitsService $usageLimits) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }
        if (! $actor->isCompanyAdmin() && ! $actor->isSystemAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar usuários.',
            ], 403);
        }

        $companyId = $actor->isSystemAdmin()
            ? (int) $request->integer('company_id', 0)
            : (int) $actor->company_id;

        if ($companyId <= 0) {
            return response()->json([
                'authenticated' => true,
                'users' => [],
            ]);
        }

        $users = User::query()
            ->where('company_id', $companyId)
            ->whereIn('role', User::companyRoleValues())
            ->with(['company:id,name', 'areas:id,name,company_id'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'company_id', 'is_active', 'can_use_ai', 'disabled_at', 'created_at']);

        try {
            $users->loadMissing('appointmentStaffProfile');
        } catch (\Throwable) {
            // Tabela pode não existir ainda em produção
        }

        $companyName = $actor->isSystemAdmin()
            ? ($users->first()?->company?->name ?? null)
            : $actor->company?->name;

        return response()->json([
            'authenticated' => true,
            'company' => [
                'id' => $companyId,
                'name' => $companyName,
            ],
            'users' => $users->map(fn(User $user) => $this->serializeUser($user))->values(),
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }
        if (! $actor->isCompanyAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar usuários.',
            ], 403);
        }

        $validated = $request->validated();

        $companyId = (int) $actor->company_id;

        $userLimitCheck = $this->usageLimits->checkUserLimit($companyId);
        if (! $userLimitCheck['allowed']) {
            return response()->json([
                'message'       => $userLimitCheck['error_message'],
                'limit_blocked' => true,
                'current'       => $userLimitCheck['current'],
                'limit'         => $userLimitCheck['limit'],
            ], 429);
        }

        $normalizedRole = User::normalizeRole((string) $validated['role']);
        $areaIds = $this->resolveAreaIds($companyId, $validated);
        $isActive = (bool) ($validated['is_active'] ?? true);
        $canUseAi = $this->resolveCanUseAi($normalizedRole, $validated);
        $permissions = $this->resolvePermissions($normalizedRole, $validated);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $normalizedRole,
            'company_id' => $companyId,
            'is_active' => $isActive,
            'can_use_ai' => $canUseAi,
            'disabled_at' => $isActive ? null : now(),
            'permissions' => $permissions,
        ]);

        $user->areas()->sync($areaIds);
        $this->syncAppointmentProfile($companyId, $user, $validated);
        $user->load(['company:id,name', 'areas:id,name,company_id']);
        try {
            $user->load('appointmentStaffProfile');
        } catch (\Throwable) {
            // Tabela pode não existir ainda em produção
        }

        return response()->json([
            'ok'            => true,
            'user'          => $this->serializeUser($user),
            'usage_warning' => $userLimitCheck['warning'] ?? false,
            'usage_message' => $userLimitCheck['warning_message'] ?? null,
            'current_users' => $userLimitCheck['current'] ?? null,
            'max_users'     => $userLimitCheck['limit'] ?? null,
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }
        if (! $actor->isCompanyAdmin() && ! $actor->isSystemAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar usuários.',
            ], 403);
        }

        $companyId = $actor->isSystemAdmin() ? (int) $user->company_id : (int) $actor->company_id;
        if ((int) $user->company_id !== $companyId || ! in_array($user->role, User::companyRoleValues(), true)) {
            return response()->json([
                'message' => 'Usuário não pertence a empresa.',
            ], 404);
        }

        $validated = $request->validated();

        $normalizedRole = User::normalizeRole((string) $validated['role']);
        $areaIds = $this->resolveAreaIds($companyId, $validated);
        $isActive = (bool) $validated['is_active'];
        $canUseAi = $this->resolveCanUseAi($normalizedRole, $validated, $user);
        $permissions = $this->resolvePermissions($normalizedRole, $validated, $user);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $normalizedRole;
        $user->is_active = $isActive;
        $user->can_use_ai = $canUseAi;
        $user->permissions = $permissions;
        $user->disabled_at = $isActive ? null : ($user->disabled_at ?? now());
        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }
        $user->save();

        $user->areas()->sync($areaIds);
        $this->syncAppointmentProfile($companyId, $user, $validated);
        $user->load(['company:id,name', 'areas:id,name,company_id']);
        try {
            $user->load('appointmentStaffProfile');
        } catch (\Throwable) {
            // Tabela pode não existir ainda em produção
        }

        return response()->json([
            'ok' => true,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }
        if (! $actor->isCompanyAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar usuários.',
            ], 403);
        }

        $companyId = (int) $actor->company_id;
        if ((int) $user->company_id !== $companyId || ! in_array($user->role, User::companyRoleValues(), true)) {
            return response()->json([
                'message' => 'Usuário não pertence a empresa.',
            ], 404);
        }

        if ((int) $actor->id === (int) $user->id) {
            return response()->json([
                'message' => 'Você não pode excluir o próprio usuário.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     */
    private function resolveAreaIds(int $companyId, array $validated): array
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
    private function resolvePermissions(string $normalizedRole, array $validated, ?User $currentUser = null): ?array
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
        try {
            $staffProfile = $user->relationLoaded('appointmentStaffProfile')
                ? $user->appointmentStaffProfile
                : $user->appointmentStaffProfile()->first();
        } catch (\Throwable) {
            $staffProfile = null;
        }

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
            'appointment_is_staff' => $staffProfile ? (bool) $staffProfile->is_bookable : true,
            'appointment_display_name' => $staffProfile?->display_name,
            'permissions' => $user->permissions,
            'resolved_permissions' => $user->resolvedPermissions(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function syncAppointmentProfile(int $companyId, User $user, array $validated): void
    {
        if (! array_key_exists('appointment_is_staff', $validated) && ! array_key_exists('appointment_display_name', $validated)) {
            return;
        }

        try {
            $profile = AppointmentStaffProfile::query()->firstOrCreate(
                [
                    'company_id' => $companyId,
                    'user_id' => (int) $user->id,
                ],
                [
                    'display_name' => $user->name,
                    'is_bookable' => true,
                ]
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

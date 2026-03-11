<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(
        private AuditLogService $auditLog
    ) {}

    public function index(Request $request): JsonResponse
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
        $users = User::query()
            ->where('company_id', $companyId)
            ->whereIn('role', User::companyRoleValues())
            ->with(['company:id,name', 'areas:id,name,company_id'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'company_id', 'is_active', 'disabled_at', 'created_at']);

        return response()->json([
            'authenticated' => true,
            'company' => [
                'id' => $companyId,
                'name' => $actor->company?->name,
            ],
            'users' => $users->map(fn(User $user) => $this->serializeUser($user))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
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

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role' => ['required', Rule::in(User::assignableRoleValuesForCompanyAdmin())],
            'is_active' => ['sometimes', 'boolean'],
            'area_ids' => ['sometimes', 'array', 'max:50'],
            'area_ids.*' => ['integer', 'exists:areas,id'],
            'areas' => ['sometimes', 'array', 'max:50'],
            'areas.*' => ['string', 'max:120'],
        ]);

        $companyId = (int) $actor->company_id;
        $normalizedRole = User::normalizeRole((string) $validated['role']);
        $areaIds = $this->resolveAreaIds($companyId, $validated);
        $isActive = (bool) ($validated['is_active'] ?? true);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $normalizedRole,
            'company_id' => $companyId,
            'is_active' => $isActive,
            'disabled_at' => $isActive ? null : now(),
        ]);

        $user->areas()->sync($areaIds);
        $user->load(['company:id,name', 'areas:id,name,company_id']);

        $this->auditLog->record($request, 'company.user.created', $companyId, [
            'user_id' => $user->id,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'area_ids' => $areaIds,
        ]);

        return response()->json([
            'ok' => true,
            'user' => $this->serializeUser($user),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
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
                'message' => 'Usuario nao pertence a empresa.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(User::assignableRoleValuesForCompanyAdmin())],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'area_ids' => ['sometimes', 'array', 'max:50'],
            'area_ids.*' => ['integer', 'exists:areas,id'],
            'areas' => ['sometimes', 'array', 'max:50'],
            'areas.*' => ['string', 'max:120'],
        ]);

        $normalizedRole = User::normalizeRole((string) $validated['role']);
        $areaIds = $this->resolveAreaIds($companyId, $validated);
        $isActive = (bool) $validated['is_active'];

        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'disabled_at' => $user->disabled_at,
            'area_ids' => $user->areas()->pluck('areas.id')->values()->all(),
        ];

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $normalizedRole;
        $user->is_active = $isActive;
        $user->disabled_at = $isActive ? null : ($user->disabled_at ?? now());
        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }
        $user->save();

        $user->areas()->sync($areaIds);
        $user->load(['company:id,name', 'areas:id,name,company_id']);

        $this->auditLog->record($request, 'company.user.updated', $companyId, [
            'user_id' => $user->id,
            'before' => $before,
            'after' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
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
                'message' => 'Usuario nao pertence a empresa.',
            ], 404);
        }

        if ((int) $actor->id === (int) $user->id) {
            return response()->json([
                'message' => 'Você não pode excluir o próprio usuário.',
            ], 422);
        }

        $userId = $user->id;
        $user->delete();

        $this->auditLog->record($request, 'company.user.deleted', $companyId, [
            'user_id' => $userId,
        ]);

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
            'is_active' => (bool) $user->is_active,
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
        ];
    }
}

<?php

namespace App\Http\Controllers\Admin;

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
        if (! $actor || ! $actor->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $users = User::query()
            ->with(['company:id,name', 'areas:id,name,company_id'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'company_id', 'is_active', 'created_at']);

        return response()->json([
            'authenticated' => true,
            'users' => $users->map(fn(User $user) => $this->serializeUser($user))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role' => ['required', Rule::in(['admin', 'company'])],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'is_active' => ['sometimes', 'boolean'],
            'area_ids' => ['sometimes', 'array', 'max:50'],
            'area_ids.*' => ['integer', 'exists:areas,id'],
            'areas' => ['sometimes', 'array', 'max:50'],
            'areas.*' => ['string', 'max:120'],
        ]);

        if ($validated['role'] === 'company' && empty($validated['company_id'])) {
            return response()->json([
                'message' => 'Usuario company precisa de company_id.',
            ], 422);
        }

        $companyId = $validated['role'] === 'company' ? (int) ($validated['company_id'] ?? 0) : null;
        $areaIds = $this->resolveAreaIds($companyId, $validated);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'company_id' => $companyId,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $user->areas()->sync($areaIds);
        $user->load(['company:id,name', 'areas:id,name,company_id']);

        $this->auditLog->record($request, 'admin.user.created', $user->company_id, [
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
        if (! $actor || ! $actor->isAdmin()) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['admin', 'company'])],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'area_ids' => ['sometimes', 'array', 'max:50'],
            'area_ids.*' => ['integer', 'exists:areas,id'],
            'areas' => ['sometimes', 'array', 'max:50'],
            'areas.*' => ['string', 'max:120'],
        ]);

        if ($validated['role'] === 'company' && empty($validated['company_id'])) {
            return response()->json([
                'message' => 'Usuario company precisa de company_id.',
            ], 422);
        }

        $companyId = $validated['role'] === 'company' ? (int) ($validated['company_id'] ?? 0) : null;
        $areaIds = $this->resolveAreaIds($companyId, $validated);

        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'company_id' => $user->company_id,
            'is_active' => $user->is_active,
            'area_ids' => $user->areas()->pluck('areas.id')->values()->all(),
        ];

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $validated['role'];
        $user->company_id = $companyId;
        $user->is_active = (bool) $validated['is_active'];
        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }
        $user->save();

        $user->areas()->sync($areaIds);
        $user->load(['company:id,name', 'areas:id,name,company_id']);

        $this->auditLog->record($request, 'admin.user.updated', $user->company_id, [
            'user_id' => $user->id,
            'before' => $before,
            'after' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'company_id' => $user->company_id,
                'is_active' => $user->is_active,
                'area_ids' => $areaIds,
            ],
        ]);

        return response()->json([
            'ok' => true,
            'user' => $this->serializeUser($user),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     */
    private function resolveAreaIds(?int $companyId, array $validated): array
    {
        if (! $companyId) {
            return [];
        }

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
                'area_ids' => ['Area informada nao pertence a empresa selecionada.'],
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
            'role' => $user->role,
            'company_id' => $user->company_id,
            'is_active' => (bool) $user->is_active,
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


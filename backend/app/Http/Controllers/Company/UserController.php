<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Actions\Company\User\CreateCompanyUserAction;
use App\Actions\Company\User\UpdateCompanyUserAction;
use App\Data\ActionResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreUserRequest;
use App\Http\Requests\Company\UpdateUserRequest;
use App\Models\Area;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly CreateCompanyUserAction $createUserAction,
        private readonly UpdateCompanyUserAction $updateUserAction,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($guard = $this->guardUnauthenticated($request)) {
            return $guard;
        }
        $actor = $request->user();
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
        if ($guard = $this->guardUnauthenticated($request)) {
            return $guard;
        }
        $actor = $request->user();
        if (! $actor->isCompanyAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar usuários.',
            ], 403);
        }

        $result = $this->createUserAction->handle((int) $actor->company_id, $request->validated(), $actor);

        if ($result->status !== 201) {
            return $result->toResponse();
        }

        /** @var \App\Models\User $user */
        $user = $result->body['user'];
        $this->loadUserWithRelations($user);

        return ActionResponse::created(
            array_merge($result->body, ['user' => $this->serializeUser($user)])
        )->toResponse();
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        if ($guard = $this->guardUnauthenticated($request)) {
            return $guard;
        }
        $actor = $request->user();
        if (! $actor->isCompanyAdmin() && ! $actor->isSystemAdmin()) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Somente admin da empresa pode gerenciar usuários.',
            ], 403);
        }

        $companyId = $actor->isSystemAdmin() ? (int) $user->company_id : (int) $actor->company_id;
        if ((int) $user->company_id !== $companyId || ! in_array($user->role, User::companyRoleValues(), true)) {
            return response()->json(['message' => 'Usuário não pertence a empresa.'], 404);
        }

        $this->updateUserAction->handle($companyId, $user, $request->validated());
        $this->loadUserWithRelations($user);

        return response()->json([
            'ok'   => true,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($guard = $this->guardUnauthenticated($request)) {
            return $guard;
        }
        $actor = $request->user();
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
     * Carrega as relações necessárias após criar ou atualizar um usuário.
     * O try/catch em appointmentStaffProfile protege ambientes onde a tabela
     * ainda não existe (deploy gradual).
     */
    private function loadUserWithRelations(User $user): void
    {
        $user->load(['company:id,name', 'areas:id,name,company_id']);
        try {
            $user->load('appointmentStaffProfile');
        } catch (\Throwable) {
            // Tabela pode não existir ainda em produção
        }
    }

}

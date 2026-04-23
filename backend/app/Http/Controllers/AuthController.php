<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Models\User;
use App\Services\Ai\AiAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly AiAccessService $aiAccessService
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::attempt($credentials, true)) {
            return response()->json([
                'message' => 'Credenciais invalidas.',
            ], 422);
        }

        $request->session()->regenerate();
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'Falha ao autenticar usuário.',
            ], 500);
        }
        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Usuário inativo. Procure um administrador.',
            ], 403);
        }

        $user->loadMissing('company.reseller', 'reseller');

        return response()->json([
            'authenticated' => true,
            'user' => $this->userPayload($user),
            'reseller' => $this->resellerPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }

        $user->loadMissing('company.reseller', 'reseller');

        return response()->json([
            'authenticated' => true,
            'user' => $this->userPayload($user),
            'reseller' => $this->resellerPayload($user),
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 403);
        }

        $user->name = $request->validated('name');
        $user->save();

        $user->loadMissing('company.reseller', 'reseller');

        return response()->json([
            'user' => $this->userPayload($user),
            'reseller' => $this->resellerPayload($user),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 403);
        }

        $validated = $request->validated();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Senha atual incorreta.',
                'errors' => ['current_password' => ['Senha atual incorreta.']],
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return response()->json([
            'ok' => true,
            'message' => 'Senha alterada com sucesso.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'ok' => true,
        ]);
    }

    private function resellerPayload($user): ?array
    {
        $reseller = $user->company?->reseller ?? $user->reseller;

        if (! $reseller) {
            return null;
        }

        return [
            'id'            => $reseller->id,
            'slug'          => $reseller->slug,
            'name'          => $reseller->name,
            'logo_url'      => $reseller->logo_url,
            'primary_color' => $reseller->primary_color,
        ];
    }

    private function userPayload($user): array
    {
        $settings = $this->aiAccessService->resolveCompanySettings($user);
        $canUseInternalAi = $this->aiAccessService->canUseInternalAi($user, $settings);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => User::normalizeRole($user->role),
            'company_id' => $user->company_id,
            'company_name' => $user->company?->name,
            'reseller_id' => $user->reseller_id ?? $user->company?->reseller_id,
            'reseller_name' => $user->reseller?->name ?? $user->company?->reseller?->name,
            'can_manage_users' => $user->canManageCompanyUsers(),
            'can_use_ai' => $canUseInternalAi,
            'can_access_internal_ai_chat' => $canUseInternalAi,
            'can_manage_ai' => $this->aiAccessService->canManageAi($user),
            'permissions' => $user->resolvedPermissions(),
        ];
    }
}
